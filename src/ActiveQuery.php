<?php

namespace rock\sphinx;

use rock\db\common\ActiveQueryInterface;
use rock\db\common\ActiveQueryTrait;
use rock\db\common\ActiveRelationTrait;
use rock\db\common\ConnectionInterface;

/**
 * ActiveQuery represents a Sphinx query associated with an Active Record class.
 *
 * An ActiveQuery can be a normal query or be used in a relational context.
 *
 * ActiveQuery instances are usually created by {@see \rock\sphinx\ActiveRecord::find()} and {@see \rock\sphinx\ActiveRecord::findBySql()}.
 * Relational queries are created by {@see \rock\sphinx\ActiveRecord::hasOne()} and {@see \rock\sphinx\ActiveRecord::hasMany()}.
 *
 * Normal Query
 * ------------
 *
 * Because ActiveQuery extends from {@see \rock\sphinx\Query}, one can use query methods, such as {@see \rock\sphinx\Query::where()},
 * {@see \rock\sphinx\Query::orderBy()} to customize the query options.
 *
 * ActiveQuery also provides the following additional query options:
 *
 * - {@see \rock\db\common\ActiveQueryInterface::with()}: list of relations that this query should be performed with.
 * - {@see \rock\db\common\ActiveQueryInterface::indexBy()}: the name of the column by which the query result should be indexed.
 * - {@see \rock\db\common\ActiveQueryInterface::asArray()}: whether to return each record as an array.
 *
 * These options can be configured using methods of the same name. For example:
 *
 * ```php
 * $articles = Article::find()->with('source')->asArray()->all();
 * ```
 *
 * ActiveQuery allows to build the snippets using sources provided by ActiveRecord.
 * You can use {@see \rock\sphinx\ActiveQuery::snippetByModel()} method to enable this.
 * For example:
 *
 * ```php
 * class Article extends ActiveRecord
 * {
 *     public function getSource()
 *     {
 *         return $this->hasOne('db', ArticleDb::className(), ['id' => 'id']);
 *     }
 *
 *     public function getSnippetSource()
 *     {
 *         return $this->source->content;
 *     }
 *
 *     ...
 * }
 *
 * $articles = Article::find()->with('source')->snippetByModel()->all();
 * ```
 *
 * Relational query
 * ----------------
 *
 * In relational context ActiveQuery represents a relation between two Active Record classes.
 *
 * Relational ActiveQuery instances are usually created by calling {@see \rock\sphinx\ActiveRecord::hasOne()} and
 * {@see \rock\sphinx\ActiveRecord::hasMany()}. An Active Record class declares a relation by defining
 * a getter method which calls one of the above methods and returns the created ActiveQuery object.
 *
 * A relation is specified by {@see \rock\db\ActiveRelationTrait::$link} which represents the association between columns
 * of different tables; and the multiplicity of the relation is indicated by {@see \rock\db\ActiveRelationTrait::$multiple}.
 *
 * If a relation involves a pivot table, it may be specified by {@see \rock\db\common\ActiveQueryInterface::via()}.
 * This methods may only be called in a relational context. Same is true for {@see \rock\db\ActiveRelationTrait::inverseOf()}, which
 * marks a relation as inverse of another relation.
 */
class ActiveQuery extends Query implements ActiveQueryInterface
{
    use ActiveQueryTrait;
    use ActiveRelationTrait;

    /**
     * @event Event an event that is triggered when the query is initialized via {@see \rock\base\ObjectInterface::init()}.
     */
    const EVENT_INIT = 'init';

    /**
     * @var string the SQL statement to be executed for retrieving AR records.
     * This is set by {@see \rock\sphinx\ActiveRecord::findBySql()}.
     */
    public $sql;

    /**
     * Constructor.
     * @param array $modelClass the model class associated with this query
     * @param array $config configurations to be applied to the newly created query object
     */
    public function __construct($modelClass, $config = [])
    {
        $this->modelClass = $modelClass;
        parent::__construct($config);
    }

    /**
     * Initializes the object.
     * This method is called at the end of the constructor. The default implementation will trigger
     * an {@see \rock\sphinx\ActiveQuery::EVENT_INIT} event. If you override this method, make sure you call the parent implementation at the end
     * to ensure triggering of the event.
     */
    public function init()
    {
        $this->trigger(self::EVENT_INIT);
    }

    /**
     * @return Connection
     */
    public function getConnection()
    {
        if ($this->connection instanceof Connection) {
            return $this->calculateCacheParams($this->connection);
        }
        /** @var ActiveRecord $modelClass */
        $modelClass = $this->modelClass;
        $this->connection = $modelClass::getConnection();
        return $this->calculateCacheParams($this->connection);
    }

    /**
     * Sets the {@see \rock\sphinx\Query::$snippetCallback} to {@see \rock\sphinx\ActiveQuery::fetchSnippetSourceFromModels()}, which allows to
     * fetch the snippet source strings from the Active Record models, using method
     * {@see \rock\sphinx\ActiveRecord::getSnippetSource()}.
     * For example:
     *
     * ```php
     * class Article extends ActiveRecord
     * {
     *     public function getSnippetSource()
     *     {
     *         return file_get_contents('/path/to/source/files/' . $this->id . '.txt');;
     *     }
     * }
     *
     * $articles = Article::find()->snippetByModel()->all();
     * ```
     *
     * Warning: this option should NOT be used with {@see \rock\db\ActiveQueryTrait::$asArray} at the same time!
     * @return static the query object itself
     */
    public function snippetByModel()
    {
        $this->snippetCallback([$this, 'fetchSnippetSourceFromModels']);

        return $this;
    }

    /**
     * Executes query and returns a single row of result.
     *
     * @param ConnectionInterface $connection the DB connection used to create the DB command.
     * If null, the DB connection returned by {@see \rock\db\ActiveQueryTrait::$modelClass} will be used.
     * @return ActiveRecord|array|null a single row of query result. Depending on the setting of {@see \rock\db\ActiveQueryTrait::$asArray},
     * the query result may be either an array or an ActiveRecord object. Null will be returned
     * if the query results in nothing.
     */
    public function one(ConnectionInterface $connection = null)
    {
        return parent::one($connection);
    }

    /**
     * Executes query and returns all results as an array.
     *
     * @param ConnectionInterface $connection the DB connection used to create the DB command.
     * If null, the DB connection returned by {@see \rock\db\ActiveQueryTrait::$modelClass} will be used.
     * @return array|ActiveRecord[] the query results. If the query results in nothing, an empty array will be returned.
     */
    public function all(ConnectionInterface $connection = null)
    {
        return parent::all($connection);
    }

    /**
     * @inheritdoc
     */
    public function prepareResult(array $rows, ConnectionInterface $connection = null)
    {
        if (empty($rows)) {
            return [];
        }
        $models = $this->createModels($rows);
        if (!empty($this->with)) {
            if (isset($this->queryBuild->entities)) {
                $this->queryBuild->entities = [];
            }
            $this->findWith($this->with, $models);
        }
        $models = $this->fillUpSnippets($models);
        if (!$this->asArray) {
            foreach ($models as $model) {
                $model->afterFind();
            }
        } else {
            // event after
            /** @var ActiveRecord $selfModel */
            $selfModel = new $this->modelClass;
            $selfModel->afterFind($models);
        }
        return $models;
    }

    /** @var  QueryBuilder */
    private $queryBuild;

    /**
     * Creates a DB command that can be used to execute this query.
     *
     * @param ConnectionInterface $connection the DB connection used to create the DB command.
     * If null, the DB connection returned by {@see \rock\db\ActiveQueryTrait::$modelClass} will be used.
     * @return Command the created DB command instance.
     */
    public function createCommand(ConnectionInterface $connection = null)
    {
        if ($this->primaryModel !== null) {
            // lazy loading a relational query
            if ($this->via instanceof self) {
                // via pivot index
                $viaModels = $this->via->findPivotRows([$this->primaryModel]);
                $this->filterByModels($viaModels);
            } elseif (is_array($this->via)) {
                // via relation
                /** @var ActiveQuery $viaQuery */
                list($viaName, $viaQuery) = $this->via;
                if ($viaQuery->multiple) {
                    $viaModels = $viaQuery->all();
                    $this->primaryModel->populateRelation($viaName, $viaModels);
                } else {
                    $model = $viaQuery->one();
                    $this->primaryModel->populateRelation($viaName, $model);
                    $viaModels = $model === null ? [] : [$model];
                }
                $this->filterByModels($viaModels);
            } else {
                $this->filterByModels([$this->primaryModel]);
            }
        }

        if (isset($connection)) {
            $this->setConnection($connection);
        }
        $connection = $this->getConnection();

        $entities = [];
        if ($this->sql === null) {
            $build =  $connection->getQueryBuilder();
            $result = $build->build($this);
            $entities = $build->entities;
            $this->queryBuild = $build;
            list ($sql, $params) = $result;
        } else {
            $sql = $this->sql;
            $params = $this->params;
        }
        $command = $connection->createCommand($sql, $params);
        $command->entities = $entities;

        return $command;
    }


    /**
     * Fetches the source for the snippets using {@see \rock\sphinx\ActiveRecord::getSnippetSource()} method.
     *
     * @param ActiveRecord[] $models raw query result rows.
     * @throws SphinxException if {@see \rock\db\ActiveQueryTrait::$asArray} enabled.
     * @return array snippet source strings
     */
    protected function fetchSnippetSourceFromModels($models)
    {
        if ($this->asArray) {
            throw new SphinxException('"' . __METHOD__ . '" unable to determine snippet source from plain array. Either disable "asArray" option or use regular "snippetCallback"');
        }
        $result = [];
        foreach ($models as $model) {
            $result[] = $model->getSnippetSource();
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    protected function callSnippets(array $source)
    {
        $from = $this->from;
        if (empty($from)) {
            /** @var ActiveRecord $modelClass */
            $modelClass = $this->modelClass;
            $tableName = $modelClass::indexName();
            $from = [$tableName];
        }

        return $this->callSnippetsInternal($source, $from[0]);
    }
}
