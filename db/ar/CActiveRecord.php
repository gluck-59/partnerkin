<?php
/**
 * CActiveRecord class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2010 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

/**
 * CActiveRecord is the base class for classes representing relational data.
 *
 * It implements the active record design pattern, a popular Object-Relational Mapping (ORM) technique.
 * Please check {@link http://www.yiiframework.com/doc/guide/database.ar the Guide} for more details
 * about this class.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id: CActiveRecord.php 2080 2010-05-01 12:40:20Z qiang.xue $
 * @package system.db.ar
 * @since 1.0
 */
abstract class CActiveRecord extends CModel
{
	const BELONGS_TO='CBelongsToRelation';
	const HAS_ONE='CHasOneRelation';
	const HAS_MANY='CHasManyRelation';
	const MANY_MANY='CManyManyRelation';
	const STAT='CStatRelation';

	/**
	 * @var CDbConnection the default database connection for all active record classes.
	 * By default, this is the 'db' application component.
	 * @see getDbConnection
	 */
	public static $db;

	private static $_models=array();			// class name => model

	private $_md;								// meta data
	private $_new=false;						// whether this instance is new or not
	private $_attributes=array();				// attribute name => attribute value
	private $_related=array();					// attribute name => related objects
	private $_c;								// query criteria (used by finder only)
	private $_pk;								// old primary key value


	/**
	 * Constructor.
	 * @param string scenario name. See {@link CModel::scenario} for more details about this parameter.
	 */
	public function __construct($scenario='insert')
	{
		if($scenario===null) // internally used by populateRecord() and model()
			return;

		$this->setScenario($scenario);
		$this->setIsNewRecord(true);
		$this->_attributes=$this->getMetaData()->attributeDefaults;

		$this->init();

		$this->attachBehaviors($this->behaviors());
		$this->afterConstruct();
	}

	/**
	 * Initializes this model.
	 * This method is invoked when an AR instance is newly created and has
	 * its {@link scenario} set.
	 * You may override this method to provide code that is needed to initialize the model (e.g. setting
	 * initial property values.)
	 * @since 1.0.8
	 */
	public function init()
	{
	}

	/**
	 * PHP sleep magic method.
	 * This method ensures that the model meta data reference is set to null.
	 */
	public function __sleep()
	{
		$this->_md=null;
		return array_keys((array)$this);
	}

	/**
	 * PHP getter magic method.
	 * This method is overridden so that AR attributes can be accessed like properties.
	 * @param string property name
	 * @return mixed property value
	 * @see getAttribute
	 */
	public function __get($name)
	{
		if(isset($this->_attributes[$name]))
			return $this->_attributes[$name];
		else if(isset($this->getMetaData()->columns[$name]))
			return null;
		else if(isset($this->_related[$name]))
			return $this->_related[$name];
		else if(isset($this->getMetaData()->relations[$name]))
			return $this->getRelated($name);
		else
			return parent::__get($name);
	}

	/**
	 * PHP setter magic method.
	 * This method is overridden so that AR attributes can be accessed like properties.
	 * @param string property name
	 * @param mixed property value
	 */
	public function __set($name,$value)
	{
		if($this->setAttribute($name,$value)===false)
		{
			if(isset($this->getMetaData()->relations[$name]))
				$this->_related[$name]=$value;
			else
				parent::__set($name,$value);
		}
	}

	/**
	 * Checks if a property value is null.
	 * This method overrides the parent implementation by checking
	 * if the named attribute is null or not.
	 * @param string the property name or the event name
	 * @return boolean whether the property value is null
	 * @since 1.0.1
	 */
	public function __isset($name)
	{
		if(isset($this->_attributes[$name]))
			return true;
		else if(isset($this->getMetaData()->columns[$name]))
			return false;
		else if(isset($this->_related[$name]))
			return true;
		else if(isset($this->getMetaData()->relations[$name]))
			return $this->getRelated($name)!==null;
		else
			return parent::__isset($name);
	}

	/**
	 * Sets a component property to be null.
	 * This method overrides the parent implementation by clearing
	 * the specified attribute value.
	 * @param string the property name or the event name
	 * @since 1.0.1
	 */
	public function __unset($name)
	{
		if(isset($this->getMetaData()->columns[$name]))
			unset($this->_attributes[$name]);
		else if(isset($this->getMetaData()->relations[$name]))
			unset($this->_related[$name]);
		else
			parent::__unset($name);
	}

	/**
	 * Calls the named method which is not a class method.
	 * Do not call this method. This is a PHP magic method that we override
	 * to implement the named scope feature.
	 * @param string the method name
	 * @param array method parameters
	 * @return mixed the method return value
	 * @since 1.0.5
	 */
	public function __call($name,$parameters)
	{
		if(isset($this->getMetaData()->relations[$name]))
		{
			if(empty($parameters))
				return $this->getRelated($name,false);
			else
				return $this->getRelated($name,false,$parameters[0]);
		}

		$scopes=$this->scopes();
		if(isset($scopes[$name]))
		{
			$this->getDbCriteria()->mergeWith($scopes[$name]);
			return $this;
		}

		return parent::__call($name,$parameters);
	}

	/**
	 * Returns the related record(s).
	 * This method will return the related record(s) of the current record.
	 * If the relation is HAS_ONE or BELONGS_TO, it will return a single object
	 * or null if the object does not exist.
	 * If the relation is HAS_MANY or MANY_MANY, it will return an array of objects
	 * or an empty array.
	 * @param string the relation name (see {@link relations})
	 * @param boolean whether to reload the related objects from database. Defaults to false.
	 * @param array additional parameters that customize the query conditions as specified in the relation declaration.
	 * This parameter has been available since version 1.0.5.
	 * @return mixed the related object(s).
	 * @throws CDbException if the relation is not specified in {@link relations}.
	 * @since 1.0.2
	 */
	public function getRelated($name,$refresh=false,$params=array())
	{
		if(!$refresh && $params===array() && (isset($this->_related[$name]) || array_key_exists($name,$this->_related)))
			return $this->_related[$name];

		$md=$this->getMetaData();
		if(!isset($md->relations[$name]))
			throw new CDbException(Yii::t('yii','{class} does not have relation "{name}".',
				array('{class}'=>get_class($this), '{name}'=>$name)));

		Yii::trace('lazy loading '.get_class($this).'.'.$name,'system.db.ar.CActiveRecord');
		$relation=$md->relations[$name];
		if($this->getIsNewRecord() && ($relation instanceof CHasOneRelation || $relation instanceof CHasManyRelation))
			return $relation instanceof CHasOneRelation ? null : array();

		if($params!==array()) // dynamic query
		{
			$exists=isset($this->_related[$name]) || array_key_exists($name,$this->_related);
			if($exists)
				$save=$this->_related[$name];
			unset($this->_related[$name]);
			$r=array($name=>$params);
		}
		else
			$r=$name;

		$finder=new CActiveFinder($this,$r);
		$finder->lazyFind($this);

		if(!isset($this->_related[$name]))
		{
			if($relation instanceof CHasManyRelation)
				$this->_related[$name]=array();
			else if($relation instanceof CStatRelation)
				$this->_related[$name]=$relation->defaultValue;
			else
				$this->_related[$name]=null;
		}

		if($params!==array())
		{
			$results=$this->_related[$name];
			if($exists)
				$this->_related[$name]=$save;
			else
				unset($this->_related[$name]);
			return $results;
		}
		else
			return $this->_related[$name];
	}

	/**
	 * Returns a value indicating whether the named related object(s) has been loaded.
	 * @param string the relation name
	 * @return booolean a value indicating whether the named related object(s) has been loaded.
	 * @since 1.0.3
	 */
	public function hasRelated($name)
	{
		return isset($this->_related[$name]) || array_key_exists($name,$this->_related);
	}

	/**
	 * Returns the query criteria associated with this model.
	 * @param boolean whether to create a criteria instance if it does not exist. Defaults to true.
	 * @return CDbCriteria the query criteria that is associated with this model.
	 * This criteria is mainly used by {@link scopes named scope} feature to accumulate
	 * different criteria specifications.
	 * @since 1.0.5
	 */
	public function getDbCriteria($createIfNull=true)
	{
		if($this->_c===null)
		{
			if(($c=$this->defaultScope())!==array() || $createIfNull)
				$this->_c=new CDbCriteria($c);
		}
		return $this->_c;
	}

	/**
	 * Returns the default named scope that should be implicitly applied to all queries for this model.
	 * Note, default scope only applies to SELECT queries. It is ignored for INSERT, UPDATE and DELETE queries.
	 * The default implementation simply returns an empty array. You may override this method
	 * if the model needs to be queried with some default criteria (e.g. only active records should be returned).
	 * @return array the query criteria. This will be used as the parameter to the constructor
	 * of {@link CDbCriteria}.
	 * @since 1.0.5
	 */
	public function defaultScope()
	{
		return array();
	}

	/**
	 * Resets all scopes and criterias applied including default scope.
	 *
	 * @return CActiveRecord
	 * @since 1.1.2
	 */
	public function resetScope()
	{
		if($this->_c!==null)
			$this->_c=new CDbCriteria();

		return $this;
	}

	/**
	 * Returns the static model of the specified AR class.
	 * The model returned is a static instance of the AR class.
	 * It is provided for invoking class-level methods (something similar to static class methods.)
	 *
	 * EVERY derived AR class must override this method as follows,
	 * <pre>
	 * public static function model($className=__CLASS__)
	 * {
	 *     return parent::model($className);
	 * }
	 * </pre>
	 *
	 * @param string active record class name.
	 * @return CActiveRecord active record model instance.
	 */
	public static function model($className=__CLASS__)
	{
		if(isset(self::$_models[$className]))
			return self::$_models[$className];
		else
		{
			$model=self::$_models[$className]=new $className(null);
			$model->_md=new CActiveRecordMetaData($model);
			$model->attachBehaviors($model->behaviors());
			return $model;
		}
	}

	/**
	 * @return CActiveRecordMetaData the meta for this AR class.
	 */
	public function getMetaData()
	{
		if($this->_md!==null)
			return $this->_md;
		else
			return $this->_md=self::model(get_class($this))->_md;
	}

	/**
	 * Refreshes the meta data for this AR class.
	 * By calling this method, this AR class will regenerate the meta data needed.
	 * This is useful if the table schema has been changed and you want to use the latest
	 * available table schema. Make sure you have called {@link CDbSchema::refresh}
	 * before you call this method. Otherwise, old table schema data will still be used.
	 * @since 1.0.8
	 */
	public function refreshMetaData()
	{
		$finder=self::model(get_class($this));
		$finder->_md=new CActiveRecordMetaData($finder);
		if($this!==$finder)
			$this->_md=$finder->_md;
	}

	/**
	 * Returns the name of the associated database table.
	 * By default this method returns the class name as the table name.
	 * You may override this method if the table is not named after this convention.
	 * @return string the table name
	 */
	public function tableName()
	{
		return get_class($this);
	}

	/**
	 * Returns the primary key of the associated database table.
	 * This method is meant to be overridden in case when the table is not defined with a primary key
	 * (for some legency database). If the table is already defined with a primary key,
	 * you do not need to override this method. The default implementation simply returns null,
	 * meaning using the primary key defined in the database.
	 * @return mixed the primary key of the associated database table.
	 * If the key is a single column, it should return the column name;
	 * If the key is a composite one consisting of several columns, it should
	 * return the array of the key column names.
	 * @since 1.0.4
	 */
	public function primaryKey()
	{
	}

	/**
	 * This method should be overridden to declare related objects.
	 *
	 * There are four types of relations that may exist between two active record objects:
	 * <ul>
	 * <li>BELONGS_TO: e.g. a member belongs to a team;</li>
	 * <li>HAS_ONE: e.g. a member has at most one profile;</li>
	 * <li>HAS_MANY: e.g. a team has many members;</li>
	 * <li>MANY_MANY: e.g. a member has many skills and a skill belongs to a member.</li>
	 * </ul>
	 *
	 * Besides the above relation types, a special relation called STAT is also supported
	 * that can be used to perform statistical query (or aggregational query).
	 * It retrieves the aggregational information about the related objects, such as the number
	 * of comments for each post, the average rating for each product, etc.
	 *
	 * Each kind of related objects is defined in this method as an array with the following elements:
	 * <pre>
	 * 'varName'=>array('relationType', 'className', 'foreignKey', ...additional options)
	 * </pre>
	 * where 'varName' refers to the name of the variable/property that the related object(s) can
	 * be accessed through; 'relationType' refers to the type of the relation, which can be one of the
	 * following four constants: self::BELONGS_TO, self::HAS_ONE, self::HAS_MANY and self::MANY_MANY;
	 * 'className' refers to the name of the active record class that the related object(s) is of;
	 * and 'foreignKey' states the foreign key that relates the two kinds of active record.
	 * Note, for composite foreign keys, they must be listed together, separating with space or comma;
	 * and for foreign keys used in MANY_MANY relation, the joining table must be declared as well
	 * (e.g. 'joinTable(fk1, fk2)').
	 *
	 * Additional options may be specified as name-value pairs in the rest array elements:
	 * <ul>
	 * <li>'select': string|array, a list of columns to be selected. Defaults to '*', meaning all columns.
	 *   Column names should be disambiguated if they appear in an expression (e.g. COUNT(relationName.name) AS name_count).</li>
	 * <li>'condition': string, the WHERE clause. Defaults to empty. Note, column references need to
	 *   be disambiguated with prefix 'relationName.' (e.g. relationName.age&gt;20)</li>
	 * <li>'order': string, the ORDER BY clause. Defaults to empty. Note, column references need to
	 *   be disambiguated with prefix 'relationName.' (e.g. relationName.age DESC)</li>
	 * <li>'with': string|array, a list of child related objects that should be loaded together with this object.
	 *   Note, this is only honored by lazy loading, not eager loading.</li>
	 * <li>'joinType': type of join. Defaults to 'LEFT OUTER JOIN'.</li>
	 * <li>'alias': the alias for the table associated with this relationship.
	 *   This option has been available since version 1.0.1. It defaults to null,
	 *   meaning the table alias is the same as the relation name.</li>
     * <li>'params': the parameters to be bound to the generated SQL statement.
	 *   This should be given as an array of name-value pairs. This option has been
	 *   available since version 1.0.3.</li>
	 * <li>'on': the ON clause. The condition specified here will be appended
	 *   to the joining condition using the AND operator. This option has been
	 *   available since version 1.0.2.</li>
	 * <li>'index': the name of the column whose values should be used as keys
	 *   of the array that stores related objects. This option is only available to
	 *   HAS_MANY and MANY_MANY relations. This option has been available since version 1.0.7.</li>
	 * </ul>
	 *
	 * The following options are available for certain relations when lazy loading:
	 * <ul>
	 * <li>'group': string, the GROUP BY clause. Defaults to empty. Note, column references need to
	 *   be disambiguated with prefix 'relationName.' (e.g. relationName.age). This option only applies to HAS_MANY and MANY_MANY relations.</li>
	 * <li>'having': string, the HAVING clause. Defaults to empty. Note, column references need to
	 *   be disambiguated with prefix 'relationName.' (e.g. relationName.age). This option only applies to HAS_MANY and MANY_MANY relations.</li>
	 * <li>'limit': limit of the rows to be selected. This option does not apply to BELONGS_TO relation.</li>
	 * <li>'offset': offset of the rows to be selected. This option does not apply to BELONGS_TO relation.</li>
	 * </ul>
	 *
	 * Below is an example declaring related objects for 'Post' active record class:
	 * <pre>
	 * return array(
	 *     'author'=>array(self::BELONGS_TO, 'User', 'authorID'),
	 *     'comments'=>array(self::HAS_MANY, 'Comment', 'postID', 'with'=>'author', 'order'=>'createTime DESC'),
	 *     'tags'=>array(self::MANY_MANY, 'Tag', 'PostTag(postID, tagID)', 'order'=>'name'),
	 * );
	 * </pre>
	 *
	 * @return array list of related object declarations. Defaults to empty array.
	 */
	public function relations()
	{
		return array();
	}

	/**
	 * Returns the declaration of named scopes.
	 * A named scope represents a query criteria that can be chained together with
	 * other named scopes and applied to a query. This method should be overridden
	 * by child classes to declare named scopes for the particular AR classes.
	 * For example, the following code declares two named scopes: 'recently' and
	 * 'published'.
	 * <pre>
	 * return array(
	 *     'published'=>array(
	 *           'condition'=>'status=1',
	 *     ),
	 *     'recently'=>array(
	 *           'order'=>'createTime DESC',
	 *           'limit'=>5,
	 *     ),
	 * );
	 * </pre>
	 * If the above scopes are declared in a 'Post' model, we can perform the following
	 * queries:
	 * <pre>
	 * $posts=Post::model()->published()->findAll();
	 * $posts=Post::model()->published()->recently()->findAll();
	 * $posts=Post::model()->published()->with('comments')->findAll();
	 * </pre>
	 * Note that the last query is a relational query.
	 *
	 * @return array the scope definition. The array keys are scope names; the array
	 * values are the corresponding scope definitions. Each scope definition is represented
	 * as an array whose keys must be properties of {@link CDbCriteria}.
	 * @since 1.0.5
	 */
	public function scopes()
	{
		return array();
	}

	/**
	 * Returns the list of all attribute names of the model.
	 * This would return all column names of the table associated with this AR class.
	 * @return array list of attribute names.
	 * @since 1.0.1
	 */
	public function attributeNames()
	{
		return array_keys($this->getMetaData()->columns);
	}

	/**
	 * Returns the database connection used by active record.
	 * By default, the "db" application component is used as the database connection.
	 * You may override this method if you want to use a different database connection.
	 * @return CDbConnection the database connection used by active record.
	 */
	public function getDbConnection()
	{
		if(self::$db!==null)
			return self::$db;
		else
		{
			self::$db=Yii::app()->getDb();
			if(self::$db instanceof CDbConnection)
			{
				self::$db->setActive(true);
				return self::$db;
			}
			else
				throw new CDbException(Yii::t('yii','Active Record requires a "db" CDbConnection application component.'));
		}
	}

	/**
	 * @param string the relation name
	 * @return CActiveRelation the named relation declared for this AR class. Null if the relation does not exist.
	 */
	public function getActiveRelation($name)
	{
		return isset($this->getMetaData()->relations[$name]) ? $this->getMetaData()->relations[$name] : null;
	}

	/**
	 * @return CDbTableSchema the metadata of the table that this AR belongs to
	 */
	public function getTableSchema()
	{
		return $this->getMetaData()->tableSchema;
	}

	/**
	 * @return CDbCommandBuilder the command builder used by this AR
	 */
	public function getCommandBuilder()
	{
		return $this->getDbConnection()->getSchema()->getCommandBuilder();
	}

	/**
	 * @param string attribute name
	 * @return boolean whether this AR has the named attribute (table column).
	 */
	public function hasAttribute($name)
	{
		return isset($this->getMetaData()->columns[$name]);
	}

	/**
	 * Returns the named attribute value.
	 * If this is a new record and the attribute is not set before,
	 * the default column value will be returned.
	 * If this record is the result of a query and the attribute is not loaded,
	 * null will be returned.
	 * You may also use $this->AttributeName to obtain the attribute value.
	 * @param string the attribute name
	 * @return mixed the attribute value. Null if the attribute is not set or does not exist.
	 * @see hasAttribute
	 */
	public function getAttribute($name)
	{
		if(property_exists($this,$name))
			return $this->$name;
		else if(isset($this->_attributes[$name]))
			return $this->_attributes[$name];
	}

	/**
	 * Sets the named attribute value.
	 * You may also use $this->AttributeName to set the attribute value.
	 * @param string the attribute name
	 * @param mixed the attribute value.
	 * @return boolean whether the attribute exists and the assignment is conducted successfully
	 * @see hasAttribute
	 */
	public function setAttribute($name,$value)
	{
		if(property_exists($this,$name))
			$this->$name=$value;
		else if(isset($this->getMetaData()->columns[$name]))
			$this->_attributes[$name]=$value;
		else
			return false;
		return true;
	}

	/**
	 * Adds a related object to this record.
	 * This method is used internally by {@link CActiveFinder} to populate related objects.
	 * @param string attribute name
	 * @param mixed the related record
	 * @param mixed the index value in the related object collection.
	 * If true, it means using zero-based integer index.
	 * If false, it means a HAS_ONE or BELONGS_TO object and no index is needed.
	 */
	public function addRelatedRecord($name,$record,$index)
	{
		if($index!==false)
		{
			if(!isset($this->_related[$name]))
				$this->_related[$name]=array();
			if($record instanceof CActiveRecord)
			{
				if($index===true)
					$this->_related[$name][]=$record;
				else
					$this->_related[$name][$index]=$record;
			}
		}
		else if(!isset($this->_related[$name]))
			$this->_related[$name]=$record;
	}

	/**
	 * Returns all column attribute values.
	 * Note, related objects are not returned.
	 * @param mixed names of attributes whose value needs to be returned.
	 * If this is true (default), then all attribute values will be returned, including
	 * those that are not loaded from DB (null will be returned for those attributes).
	 * If this is null, all attributes except those that are not loaded from DB will be returned.
	 * @return array attribute values indexed by attribute names.
	 */
	public function getAttributes($names=true)
	{
		$attributes=$this->_attributes;
		foreach($this->getMetaData()->columns as $name=>$column)
		{
			if(property_exists($this,$name))
				$attributes[$name]=$this->$name;
			else if($names===true && !isset($attributes[$name]))
				$attributes[$name]=null;
		}
		if(is_array($names))
		{
			$attrs=array();
			foreach($names as $name)
			{
				if(property_exists($this,$name))
					$attrs[$name]=$this->$name;
				else
					$attrs[$name]=isset($attributes[$name])?$attributes[$name]:null;
			}
			return $attrs;
		}
		else
			return $attributes;
	}

	/**
	 * Saves the current record.
	 *
	 * The record is inserted as a row into the database table if its {@link isNewRecord}
	 * property is true (usually the case when the record is created using the 'new'
	 * operator). Otherwise, it will be used to update the corresponding row in the table
	 * (usually the case if the record is obtained using one of those 'find' methods.)
	 *
	 * Validation will be performed before saving the record. If the validation fails,
	 * the record will not be saved. You can call {@link getErrors()} to retrieve the
	 * validation errors.
	 *
	 * If the record is saved via insertion, its {@link isNewRecord} property will be
	 * set false, and its {@link scenario} property will be set to be 'update'.
	 * And if its primary key is auto-incremental and is not set before insertion,
	 * the primary key will be populated with the automatically generated key value.
	 *
	 * @param boolean whether to perform validation before saving the record.
	 * If the validation fails, the record will not be saved to database.
	 * @param array list of attributes that need to be saved. Defaults to null,
	 * meaning all attributes that are loaded from DB will be saved.
	 * @return boolean whether the saving succeeds
	 */
	public function save($runValidation=true,$attributes=null)
	{
		if(!$runValidation || $this->validate($attributes))
			return $this->getIsNewRecord() ? $this->insert($attributes) : $this->update($attributes);
		else
			return false;
	}

	/**
	 * @return boolean whether the record is new and should be inserted when calling {@link save}.
	 * This property is automatically set in constructor and {@link populateRecord}.
	 * Defaults to false, but it will be set to true if the instance is created using
	 * the new operator.
	 */
	public function getIsNewRecord()
	{
		return $this->_new;
	}

	/**
	 * @param boolean whether the record is new and should be inserted when calling {@link save}.
	 * @see getIsNewRecord
	 */
	public function setIsNewRecord($value)
	{
		$this->_new=$value;
	}

	/**
	 * This event is raised before the record is saved.
	 * @param CEvent the event parameter
	 * @since 1.0.2
	 */
	public function onBeforeSave($event)
	{
		$this->raiseEvent('onBeforeSave',$event);
	}

	/**
	 * This event is raised after the record is saved.
	 * @param CEvent the event parameter
	 * @since 1.0.2
	 */
	public function onAfterSave($event)
	{
		$this->raiseEvent('onAfterSave',$event);
	}

	/**
	 * This event is raised before the record is deleted.
	 * @param CEvent the event parameter
	 * @since 1.0.2
	 */
	public function onBeforeDelete($event)
	{
		$this->raiseEvent('onBeforeDelete',$event);
	}

	/**
	 * This event is raised after the record is deleted.
	 * @param CEvent the event parameter
	 * @since 1.0.2
	 */
	public function onAfterDelete($event)
	{
		$this->raiseEvent('onAfterDelete',$event);
	}

	/**
	 * This event is raised after the record instance is created by new operator.
	 * @param CEvent the event parameter
	 * @since 1.0.2
	 */
	public function onAfterConstruct($event)
	{
		$this->raiseEvent('onAfterConstruct',$event);
	}

	/**
	 * This event is raised before an AR finder performs a find call.
	 * @param CEvent the event parameter
	 * @see beforeFind
	 * @since 1.0.9
	 */
	public function onBeforeFind($event)
	{
		$this->raiseEvent('onBeforeFind',$event);
	}

	/**
	 * This event is raised after the record is instantiated by a find method.
	 * @param CEvent the event parameter
	 * @since 1.0.2
	 */
	public function onAfterFind($event)
	{
		$this->raiseEvent('onAfterFind',$event);
	}

	/**
	 * This method is invoked before saving a record (after validation, if any).
	 * The default implementation raises the {@link onBeforeSave} event.
	 * You may override this method to do any preparation work for record saving.
	 * Use {@link isNewRecord} to determine whether the saving is
	 * for inserting or updating record.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 * @return boolean whether the saving should be executed. Defaults to true.
	 */
	protected function beforeSave()
	{
		if($this->hasEventHandler('onBeforeSave'))
		{
			$event=new CModelEvent($this);
			$this->onBeforeSave($event);
			return $event->isValid;
		}
		else
			return true;
	}

	/**
	 * This method is invoked after saving a record successfully.
	 * The default implementation raises the {@link onAfterSave} event.
	 * You may override this method to do postprocessing after record saving.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 */
	protected function afterSave()
	{
		if($this->hasEventHandler('onAfterSave'))
			$this->onAfterSave(new CEvent($this));
	}

	/**
	 * This method is invoked before deleting a record.
	 * The default implementation raises the {@link onBeforeDelete} event.
	 * You may override this method to do any preparation work for record deletion.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 * @return boolean whether the record should be deleted. Defaults to true.
	 */
	protected function beforeDelete()
	{
		if($this->hasEventHandler('onBeforeDelete'))
		{
			$event=new CModelEvent($this);
			$this->onBeforeDelete($event);
			return $event->isValid;
		}
		else
			return true;
	}

	/**
	 * This method is invoked after deleting a record.
	 * The default implementation raises the {@link onAfterDelete} event.
	 * You may override this method to do postprocessing after the record is deleted.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 */
	protected function afterDelete()
	{
		if($this->hasEventHandler('onAfterDelete'))
			$this->onAfterDelete(new CEvent($this));
	}

	/**
	 * This method is invoked after a record instance is created by new operator.
	 * The default implementation raises the {@link onAfterConstruct} event.
	 * You may override this method to do postprocessing after record creation.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 */
	protected function afterConstruct()
	{
		if($this->hasEventHandler('onAfterConstruct'))
			$this->onAfterConstruct(new CEvent($this));
	}

	/**
	 * This method is invoked before an AR finder executes a find call.
	 * The find calls include {@link find}, {@link findAll}, {@link findByPk},
	 * {@link findAllByPk}, {@link findByAttributes} and {@link findAllByAttributes}.
	 * The default implementation raises the {@link onBeforeFind} event.
	 * If you override this method, make sure you call the parent implementation
	 * so that the event is raised properly.
	 * @since 1.0.9
	 */
	protected function beforeFind()
	{
		if($this->hasEventHandler('onBeforeFind'))
			$this->onBeforeFind(new CEvent($this));
	}

	/**
	 * This method is invoked after each record is instantiated by a find method.
	 * The default implementation raises the {@link onAfterFind} event.
	 * You may override this method to do postprocessing after each newly found record is instantiated.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 */
	protected function afterFind()
	{
		if($this->hasEventHandler('onAfterFind'))
			$this->onAfterFind(new CEvent($this));
	}

	/**
	 * Calls {@link beforeFind}.
	 * This method is internally used.
	 * @since 1.0.11
	 */
	public function beforeFindInternal()
	{
		$this->beforeFind();
	}

	/**
	 * Calls {@link afterFind}.
	 * This method is internally used.
	 * @since 1.0.3
	 */
	public function afterFindInternal()
	{
		$this->afterFind();
	}

	/**
	 * Inserts a row into the table based on this active record attributes.
	 * If the table's primary key is auto-incremental and is null before insertion,
	 * it will be populated with the actual value after insertion.
	 * Note, validation is not performed in this method. You may call {@link validate} to perform the validation.
	 * After the record is inserted to DB successfully, its {@link isNewRecord} property will be set false,
	 * and its {@link scenario} property will be set to be 'update'.
	 * @param array list of attributes that need to be saved. Defaults to null,
	 * meaning all attributes that are loaded from DB will be saved.
	 * @return boolean whether the attributes are valid and the record is inserted successfully.
	 * @throws CException if the record is not new
	 */
	public function insert($attributes=null)
	{
		if(!$this->getIsNewRecord())
			throw new CDbException(Yii::t('yii','The active record cannot be inserted to database because it is not new.'));
		if($this->beforeSave())
		{
			Yii::trace(get_class($this).'.insert()','system.db.ar.CActiveRecord');
			$builder=$this->getCommandBuilder();
			$table=$this->getMetaData()->tableSchema;
			$command=$builder->createInsertCommand($table,$this->getAttributes($attributes));
			if($command->execute())
			{
				$primaryKey=$table->primaryKey;
				if($table->sequenceName!==null)
				{
					if(is_string($primaryKey) && $this->$primaryKey===null)
						$this->$primaryKey=$builder->getLastInsertID($table);
					else if(is_array($primaryKey))
					{
						foreach($primaryKey as $pk)
						{
							if($this->$pk===null)
							{
								$this->$pk=$builder->getLastInsertID($table);
								break;
							}
						}
					}
				}
				$this->_pk=$this->getPrimaryKey();
				$this->afterSave();
				$this->setIsNewRecord(false);
				$this->setScenario('update');
				return true;
			}
		}
		return false;
	}

	/**
	 * Updates the row represented by this active record.
	 * All loaded attributes will be saved to the database.
	 * Note, validation is not performed in this method. You may call {@link validate} to perform the validation.
	 * @param array list of attributes that need to be saved. Defaults to null,
	 * meaning all attributes that are loaded from DB will be saved.
	 * @return boolean whether the update is successful
	 * @throws CException if the record is new
	 */
	public function update($attributes=null)
	{
		if($this->getIsNewRecord())
			throw new CDbException(Yii::t('yii','The active record cannot be updated because it is new.'));
		if($this->beforeSave())
		{
			Yii::trace(get_class($this).'.update()','system.db.ar.CActiveRecord');
			if($this->_pk===null)
				$this->_pk=$this->getPrimaryKey();
			$this->updateByPk($this->getOldPrimaryKey(),$this->getAttributes($attributes));
			$this->_pk=$this->getPrimaryKey();
			$this->afterSave();
			return true;
		}
		else
			return false;
	}

	/**
	 * Saves a selected list of attributes.
	 * Unlike {@link save}, this method only saves the specified attributes
	 * of an existing row dataset. It thus has better performance.
	 * Note, this method does neither attribute filtering nor validation.
	 * So do not use this method with untrusted data (such as user posted data).
	 * You may consider the following alternative if you want to do so:
	 * <pre>
	 * $postRecord=Post::model()->findByPk($postID);
	 * $postRecord->attributes=$_POST['post'];
	 * $postRecord->save();
	 * </pre>
	 * @param array attributes to be updated. Each element represents an attribute name
	 * or an attribute value indexed by its name. If the latter, the record's
	 * attribute will be changed accordingly before saving.
	 * @return boolean whether the update is successful
	 * @throws CException if the record is new or any database error
	 */
	public function saveAttributes($attributes)
	{
		if(!$this->getIsNewRecord())
		{
			Yii::trace(get_class($this).'.saveAttributes()','system.db.ar.CActiveRecord');
			$values=array();
			foreach($attributes as $name=>$value)
			{
				if(is_integer($name))
					$values[$value]=$this->$value;
				else
					$values[$name]=$this->$name=$value;
			}
			if($this->_pk===null)
				$this->_pk=$this->getPrimaryKey();
			if($this->updateByPk($this->getOldPrimaryKey(),$values)>0)
			{
				$this->_pk=$this->getPrimaryKey();
				return true;
			}
			else
				return false;
		}
		else
			throw new CDbException(Yii::t('yii','The active record cannot be updated because it is new.'));
	}

	/**
	 * Deletes the row corresponding to this active record.
	 * @return boolean whether the deletion is successful.
	 * @throws CException if the record is new
	 */
	public function delete()
	{
		if(!$this->getIsNewRecord())
		{
			Yii::trace(get_class($this).'.delete()','system.db.ar.CActiveRecord');
			if($this->beforeDelete())
			{
				$result=$this->deleteByPk($this->getPrimaryKey())>0;
				$this->afterDelete();
				return $result;
			}
			else
				return false;
		}
		else
			throw new CDbException(Yii::t('yii','The active record cannot be deleted because it is new.'));
	}

	/**
	 * Repopulates this active record with the latest data.
	 * @return boolean whether the row still exists in the database. If true, the latest data will be populated to this active record.
	 */
	public function refresh()
	{
		Yii::trace(get_class($this).'.refresh()','system.db.ar.CActiveRecord');
		if(!$this->getIsNewRecord() && ($record=$this->findByPk($this->getPrimaryKey()))!==null)
		{
			$this->_attributes=array();
			$this->_related=array();
			foreach($this->getMetaData()->columns as $name=>$column)
			{
				if(property_exists($this,$name))
					$this->$name=$record->$name;
				else
					$this->_attributes[$name]=$record->$name;
			}
			return true;
		}
		else
			return false;
	}

	/**
	 * Compares this active record with another one.
	 * The comparison is made by comparing the primary key values of the two active records.
	 * @return boolean whether the two active records refer to the same row in the database table.
	 */
	public function equals($record)
	{
		return $this->tableName()===$record->tableName() && $this->getPrimaryKey()===$record->getPrimaryKey();
	}

	/**
	 * @return mixed the primary key value. An array (column name=>column value) is returned if the primary key is composite.
	 * If primary key is not defined, null will be returned.
	 */
	public function getPrimaryKey()
	{
		$table=$this->getMetaData()->tableSchema;
		if(is_string($table->primaryKey))
			return $this->{$table->primaryKey};
		else if(is_array($table->primaryKey))
		{
			$values=array();
			foreach($table->primaryKey as $name)
				$values[$name]=$this->$name;
			return $values;
		}
		else
			return null;
	}

	/**
	 * Sets the primary key value.
	 * After calling this method, the old primary key value can be obtained from {@link oldPrimaryKey}.
	 * @param mixed the new primary key value. If the primary key is composite, the new value
	 * should be provided as an array (column name=>column value).
	 * @since 1.1.0
	 */
	public function setPrimaryKey($value)
	{
		$this->_pk=$this->getPrimaryKey();
		$table=$this->getMetaData()->tableSchema;
		if(is_string($table->primaryKey))
			$this->{$table->primaryKey}=$value;
		else if(is_array($table->primaryKey))
		{
			foreach($table->primaryKey as $name)
				$this->$name=$value[$name];
		}
	}

	/**
	 * Returns the old primary key value.
	 * This refers to the primary key value that is populated into the record
	 * after executing a find method (e.g. find(), findAll()).
	 * The value remains unchanged even if the primary key attribute is manually assigned with a different value.
	 * @return mixed the old primary key value. An array (column name=>column value) is returned if the primary key is composite.
	 * If primary key is not defined, null will be returned.
	 * @since 1.1.0
	 */
	public function getOldPrimaryKey()
	{
		return $this->_pk;
	}

	private function query($criteria,$all=false)
	{
		$this->applyScopes($criteria);
		if(empty($criteria->with))
		{
			if(!$all)
				$criteria->limit=1;
			$this->beforeFind();
			$command=$this->getCommandBuilder()->createFindCommand($this->getTableSchema(),$criteria);
			return $all ? $this->populateRecords($command->queryAll()) : $this->populateRecord($command->queryRow());
		}
		else
			return $this->with($criteria->with)->query($criteria,$all);
	}

	/**
	 * Applies the query scopes to the given criteria.
	 * This method merges {@link dbCriteria} with the given criteria parameter.
	 * It then resets {@link dbCriteria} to be null.
	 * @param CDbCriteria the query criteria. This parameter may be modified by merging {@link dbCriteria}.
	 * @since 1.0.12
	 */
	public function applyScopes(&$criteria)
	{
		if(($c=$this->getDbCriteria(false))!==null)
		{
			$c->mergeWith($criteria);
			$criteria=$c;
			$this->_c=null;
		}
	}

	/**
	 * Returns the default table alias to be used by the find methods.
	 * This method will return the 'alias' option if it is set in {@link defaultScope}.
	 * Otherwise, it will return 't' as the default alias.
	 * @param boolean whether to quote the alias name
	 * @return string the default table alias
	 * @since 1.1.1
	 */
	public function getTableAlias($quote=false)
	{
		if(($criteria=$this->getDbCriteria(false))!==null && $criteria->alias!='')
			$alias=$criteria->alias;
		else
			$alias='t';
		return $quote ? $this->getDbConnection()->getSchema()->quoteTableName($alias) : $alias;
	}

	/**
	 * Finds a single active record with the specified condition.
	 * @param mixed query condition or criteria.
	 * If a string, it is treated as query condition (the WHERE clause);
	 * If an array, it is treated as the initial values for constructing a {@link CDbCriteria} object;
	 * Otherwise, it should be an instance of {@link CDbCriteria}.
	 * @param array parameters to be bound to an SQL statement.
	 * This is only used when the first parameter is a string (query condition).
	 * In other cases, please use {@link CDbCriteria::params} to set parameters.
	 * @return CActiveRecord the record found. Null if no record is found.
	 */
	public function find($condition='',$params=array())
	{
		Yii::trace(get_class($this).'.find()','system.db.ar.CActiveRecord');
		$criteria=$this->getCommandBuilder()->createCriteria($condition,$params);
		return $this->query($criteria);
	}

	/**
	 * Finds all active records satisfying the specified condition.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return array list of active records satisfying the specified condition. An empty array is returned if none is found.
	 */
	public function findAll($condition='',$params=array())
	{
		Yii::trace(get_class($this).'.findAll()','system.db.ar.CActiveRecord');
		$criteria=$this->getCommandBuilder()->createCriteria($condition,$params);
		return $this->query($criteria,true);
	}

	/**
	 * Finds a single active record with the specified primary key.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param mixed primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return CActiveRecord the record found. Null if none is found.
	 */
	public function findByPk($pk,$condition='',$params=array())
	{
		Yii::trace(get_class($this).'.findByPk()','system.db.ar.CActiveRecord');
		$prefix=$this->getTableAlias(true).'.';
		$criteria=$this->getCommandBuilder()->createPkCriteria($this->getTableSchema(),$pk,$condition,$params,$prefix);
		return $this->query($criteria);
	}

	/**
	 * Finds all active records with the specified primary keys.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param mixed primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return array the records found. An empty array is returned if none is found.
	 */
	public function findAllByPk($pk,$condition='',$params=array())
	{
		Yii::trace(get_class($this).'.findAllByPk()','system.db.ar.CActiveRecord');
		$prefix=$this->getTableAlias(true).'.';
		$criteria=$this->getCommandBuilder()->createPkCriteria($this->getTableSchema(),$pk,$condition,$params,$prefix);
		return $this->query($criteria,true);
	}

	/**
	 * Finds a single active record that has the specified attribute values.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param array list of attribute values (indexed by attribute names) that the active records should match.
	 * Since version 1.0.8, an attribute value can be an array which will be used to generate an IN condition.
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return CActiveRecord the record found. Null if none is found.
	 */
	public function findByAttributes($attributes,$condition='',$params=array())
	{
		Yii::trace(get_class($this).'.findByAttributes()','system.db.ar.CActiveRecord');
		$prefix=$this->getTableAlias(true).'.';
		$criteria=$this->getCommandBuilder()->createColumnCriteria($this->getTableSchema(),$attributes,$condition,$params,$prefix);
		return $this->query($criteria);
	}

	/**
	 * Finds all active records that have the specified attribute values.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param array list of attribute values (indexed by attribute names) that the active records should match.
	 * Since version 1.0.8, an attribute value can be an array which will be used to generate an IN condition.
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return array the records found. An empty array is returned if none is found.
	 */
	public function findAllByAttributes($attributes,$condition='',$params=array())
	{
		Yii::trace(get_class($this).'.findAllByAttributes()','system.db.ar.CActiveRecord');
		$prefix=$this->getTableAlias(true).'.';
		$criteria=$this->getCommandBuilder()->createColumnCriteria($this->getTableSchema(),$attributes,$condition,$params,$prefix);
		return $this->query($criteria,true);
	}

	/**
	 * Finds a single active record with the specified SQL statement.
	 * @param string the SQL statement
	 * @param array parameters to be bound to the SQL statement
	 * @return CActiveRecord the record found. Null if none is found.
	 */
	public function findBySql($sql,$params=array())
	{
		Yii::trace(get_class($this).'.findBySql()','system.db.ar.CActiveRecord');
		$command=$this->getCommandBuilder()->createSqlCommand($sql,$params);
		return $this->populateRecord($command->queryRow());
	}

	/**
	 * Finds all active records using the specified SQL statement.
	 * @param string the SQL statement
	 * @param array parameters to be bound to the SQL statement
	 * @return array the records found. An empty array is returned if none is found.
	 */
	public function findAllBySql($sql,$params=array())
	{
		Yii::trace(get_class($this).'.findAllBySql()','system.db.ar.CActiveRecord');
		$command=$this->getCommandBuilder()->createSqlCommand($sql,$params);
		return $this->populateRecords($command->queryAll());
	}

	/**
	 * Finds the number of rows satisfying the specified query condition.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return integer the number of rows satisfying the specified query condition.
	 */
	public function count($condition='',$params=array())
	{
		Yii::trace(get_class($this).'.count()','system.db.ar.CActiveRecord');
		$builder=$this->getCommandBuilder();
		$criteria=$builder->createCriteria($condition,$params);
		$this->applyScopes($criteria);

		if(empty($criteria->with))
			return $builder->createCountCommand($this->getTableSchema(),$criteria)->queryScalar();
		else
			return $this->with($criteria->with)->count($criteria);
	}

	/**
	 * Finds the number of rows using the given SQL statement.
	 * @param string the SQL statement
	 * @param array parameters to be bound to the SQL statement
	 * @return integer the number of rows using the given SQL statement.
	 */
	public function countBySql($sql,$params=array())
	{
		Yii::trace(get_class($this).'.countBySql()','system.db.ar.CActiveRecord');
		return $this->getCommandBuilder()->createSqlCommand($sql,$params)->queryScalar();
	}

	/**
	 * Checks whether there is row satisfying the specified condition.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return boolean whether there is row satisfying the specified condition.
	 */
	public function exists($condition,$params=array())
	{
		Yii::trace(get_class($this).'.exists()','system.db.ar.CActiveRecord');
		$builder=$this->getCommandBuilder();
		$criteria=$builder->createCriteria($condition,$params);
		$table=$this->getTableSchema();
		$criteria->select=reset($table->columns)->rawName;
		$criteria->limit=1;
		$this->applyScopes($criteria);
		return $builder->createFindCommand($table,$criteria)->queryRow()!==false;
	}

	/**
	 * Specifies which related objects should be eagerly loaded.
	 * This method takes variable number of parameters. Each parameter specifies
	 * the name of a relation or child-relation. For example,
	 * <pre>
	 * // find all posts together with their author and comments
	 * Post::model()->with('author','comments')->findAll();
	 * // find all posts together with their author and the author's profile
	 * Post::model()->with('author','author.profile')->findAll();
	 * </pre>
	 * The relations should be declared in {@link relations()}.
	 *
	 * By default, the options specified in {@link relations()} will be used
	 * to do relational query. In order to customize the options on the fly,
	 * we should pass an array parameter to the with() method. The array keys
	 * are relation names, and the array values are the corresponding query options.
	 * For example,
	 * <pre>
	 * Post::model()->with(array(
	 *     'author'=>array('select'=>'id, name'),
	 *     'comments'=>array('condition'=>'approved=1', 'order'=>'createTime'),
	 * ))->findAll();
	 * </pre>
	 *
	 * This method returns a {@link CActiveFinder} instance that provides
	 * a set of find methods similar to that of CActiveRecord.
	 *
	 * Note, the possible parameters to this method have been changed since version 1.0.2.
	 * Previously, it was not possible to specify on-th-fly query options,
	 * and child-relations were specified as hierarchical arrays.
	 *
	 * @return CActiveFinder the active finder instance. If no parameter is passed in, the object itself will be returned.
	 */
	public function with()
	{
		if(func_num_args()>0)
		{
			$with=func_get_args();
			if(is_array($with[0]))  // the parameter is given as an array
				$with=$with[0];
			return new CActiveFinder($this,$with);
		}
		else
			return $this;
	}

	/**
	 * Updates records with the specified primary key(s).
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * Note, the attributes are not checked for safety and validation is NOT performed.
	 * @param mixed primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param array list of attributes (name=>$value) to be updated
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return integer the number of rows being updated
	 */
	public function updateByPk($pk,$attributes,$condition='',$params=array())
	{
		Yii::trace(get_class($this).'.updateByPk()','system.db.ar.CActiveRecord');
		$builder=$this->getCommandBuilder();
		$table=$this->getTableSchema();
		$criteria=$builder->createPkCriteria($table,$pk,$condition,$params);
		$command=$builder->createUpdateCommand($table,$attributes,$criteria);
		return $command->execute();
	}

	/**
	 * Updates records with the specified condition.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * Note, the attributes are not checked for safety and no validation is done.
	 * @param array list of attributes (name=>$value) to be updated
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return integer the number of rows being updated
	 */
	public function updateAll($attributes,$condition='',$params=array())
	{
		Yii::trace(get_class($this).'.updateAll()','system.db.ar.CActiveRecord');
		$builder=$this->getCommandBuilder();
		$criteria=$builder->createCriteria($condition,$params);
		$command=$builder->createUpdateCommand($this->getTableSchema(),$attributes,$criteria);
		return $command->execute();
	}

	/**
	 * Updates one or several counter columns.
	 * Note, this updates all rows of data unless a condition or criteria is specified.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param array the counters to be updated (column name=>increment value)
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return integer the number of rows being updated
	 */
	public function updateCounters($counters,$condition='',$params=array())
	{
		Yii::trace(get_class($this).'.updateCounters()','system.db.ar.CActiveRecord');
		$builder=$this->getCommandBuilder();
		$criteria=$builder->createCriteria($condition,$params);
		$command=$builder->createUpdateCounterCommand($this->getTableSchema(),$counters,$criteria);
		return $command->execute();
	}

	/**
	 * Deletes rows with the specified primary key.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param mixed primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return integer the number of rows deleted
	 */
	public function deleteByPk($pk,$condition='',$params=array())
	{
		Yii::trace(get_class($this).'.deleteByPk()','system.db.ar.CActiveRecord');
		$builder=$this->getCommandBuilder();
		$criteria=$builder->createPkCriteria($this->getTableSchema(),$pk,$condition,$params);
		$command=$builder->createDeleteCommand($this->getTableSchema(),$criteria);
		return $command->execute();
	}

	/**
	 * Deletes rows with the specified condition.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return integer the number of rows deleted
	 */
	public function deleteAll($condition='',$params=array())
	{
		Yii::trace(get_class($this).'.deleteAll()','system.db.ar.CActiveRecord');
		$builder=$this->getCommandBuilder();
		$criteria=$builder->createCriteria($condition,$params);
		$command=$builder->createDeleteCommand($this->getTableSchema(),$criteria);
		return $command->execute();
	}

	/**
	 * Deletes rows which match the specified attribute values.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param array list of attribute values (indexed by attribute names) that the active records should match.
	 * Since version 1.0.8, an attribute value can be an array which will be used to generate an IN condition.
	 * @param mixed query condition or criteria.
	 * @param array parameters to be bound to an SQL statement.
	 * @return CActiveRecord the record found. Null if none is found.
	 * @since 1.0.9
	 */
	public function deleteAllByAttributes($attributes,$condition='',$params=array())
	{
		Yii::trace(get_class($this).'.deleteAllByAttributes()','system.db.ar.CActiveRecord');
		$builder=$this->getCommandBuilder();
		$table=$this->getTableSchema();
		$criteria=$builder->createColumnCriteria($table,$attributes,$condition,$params);
		$command=$builder->createDeleteCommand($table,$criteria);
		return $command->execute();
	}

	/**
	 * Creates an active record with the given attributes.
	 * This method is internally used by the find methods.
	 * @param array attribute values (column name=>column value)
	 * @param boolean whether to call {@link afterFind} after the record is populated.
	 * This parameter is added in version 1.0.3.
	 * @return CActiveRecord the newly created active record. The class of the object is the same as the model class.
	 * Null is returned if the input data is false.
	 */
	public function populateRecord($attributes,$callAfterFind=true)
	{
		if($attributes!==false)
		{
			$record=$this->instantiate($attributes);
			$record->setScenario('update');
			$record->init();
			$md=$record->getMetaData();
			foreach($attributes as $name=>$value)
			{
				if(property_exists($record,$name))
					$record->$name=$value;
				else if(isset($md->columns[$name]))
					$record->_attributes[$name]=$value;
			}
			$record->_pk=$record->getPrimaryKey();
			$record->attachBehaviors($record->behaviors());
			if($callAfterFind)
				$record->afterFind();
			return $record;
		}
		else
			return null;
	}

	/**
	 * Creates a list of active records based on the input data.
	 * This method is internally used by the find methods.
	 * @param array list of attribute values for the active records.
	 * @param boolean whether to call {@link afterFind} after each record is populated.
	 * This parameter is added in version 1.0.3.
	 * @return array list of active records.
	 */
	public function populateRecords($data,$callAfterFind=true)
	{
		$records=array();
		foreach($data as $attributes)
			$records[]=$this->populateRecord($attributes,$callAfterFind);
		return $records;
	}

	/**
	 * Creates an active record instance.
	 * This method is called by {@link populateRecord} and {@link populateRecords}.
	 * You may override this method if the instance being created
	 * depends the attributes that are to be populated to the record.
	 * For example, by creating a record based on the value of a column,
	 * you may implement the so-called single-table inheritance mapping.
	 * @param array list of attribute values for the active records.
	 * @return CActiveRecord the active record
	 * @since 1.0.2
	 */
	protected function instantiate($attributes)
	{
		$class=get_class($this);
		$model=new $class(null);
		return $model;
	}

	/**
	 * Returns whether there is an element at the specified offset.
	 * This method is required by the interface ArrayAccess.
	 * @param mixed the offset to check on
	 * @return boolean
	 * @since 1.0.2
	 */
	public function offsetExists($offset)
	{
		return isset($this->getMetaData()->columns[$offset]);
	}
}


/**
 * CBaseActiveRelation is the base class for all active relations.
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id: CActiveRecord.php 2080 2010-05-01 12:40:20Z qiang.xue $
 * @package system.db.ar
 * @since 1.0.4
 */
class CBaseActiveRelation extends CComponent
{
	/**
	 * @var string name of the related object
	 */
	public $name;
	/**
	 * @var string name of the related active record class
	 */
	public $className;
	/**
	 * @var string the foreign key in this relation
	 */
	public $foreignKey;
	/**
	 * @var mixed list of column names (an array, or a string of names separated by commas) to be selected.
	 * Do not quote or prefix the column names unless they are used in an expression.
	 * In that case, you should prefix the column names with 'relationName.'.
	 */
	public $select='*';
	/**
	 * @var string WHERE clause. For {@link CActiveRelation} descendant classes, column names
	 * referenced in the condition should be disambiguated with prefix 'relationName.'.
	 */
	public $condition='';
	/**
	 * @var array the parameters that are to be bound to the condition.
	 * The keys are parameter placeholder names, and the values are parameter values.
	 */
	public $params=array();
	/**
	 * @var string GROUP BY clause. For {@link CActiveRelation} descendant classes, column names
	 * referenced in this property should be disambiguated with prefix 'relationName.'.
	 */
	public $group='';
	/**
	 * @var string HAVING clause. For {@link CActiveRelation} descendant classes, column names
	 * referenced in this property should be disambiguated with prefix 'relationName.'.
	 */
	public $having='';
	/**
	 * @var string ORDER BY clause. For {@link CActiveRelation} descendant classes, column names
	 * referenced in this property should be disambiguated with prefix 'relationName.'.
	 */
	public $order='';

	/**
	 * Constructor.
	 * @param string name of the relation
	 * @param string name of the related active record class
	 * @param string foreign key for this relation
	 * @param array additional options (name=>value). The keys must be the property names of this class.
	 */
	public function __construct($name,$className,$foreignKey,$options=array())
	{
		$this->name=$name;
		$this->className=$className;
		$this->foreignKey=$foreignKey;
		foreach($options as $name=>$value)
			$this->$name=$value;
	}

	/**
	 * Merges this relation with a criteria specified dynamically.
	 * @param array the dynamically specified criteria
	 * @since 1.0.5
	 */
	public function mergeWith($criteria)
	{
		if(isset($criteria['select']) && $this->select!==$criteria['select'])
		{
			if($this->select==='*')
				$this->select=$criteria['select'];
			else if($criteria['select']!=='*')
			{
				$select1=is_string($this->select)?preg_split('/\s*,\s*/',trim($this->select),-1,PREG_SPLIT_NO_EMPTY):$this->select;
				$select2=is_string($criteria['select'])?preg_split('/\s*,\s*/',trim($criteria['select']),-1,PREG_SPLIT_NO_EMPTY):$criteria['select'];
				$this->select=array_merge($select1,array_diff($select2,$select1));
			}
		}

		if(isset($criteria['condition']) && $this->condition!==$criteria['condition'])
		{
			if($this->condition==='')
				$this->condition=$criteria['condition'];
			else if($criteria['condition']!=='')
				$this->condition="({$this->condition}) AND ({$criteria['condition']})";
		}

		if(isset($criteria['params']) && $this->params!==$criteria['params'])
			$this->params=array_merge($this->params,$criteria['params']);

		if(isset($criteria['order']) && $this->order!==$criteria['order'])
		{
			if($this->order==='')
				$this->order=$criteria['order'];
			else if($criteria['order']!=='')
				$this->order=$criteria['order'].', '.$this->order;
		}

		if(isset($criteria['group']) && $this->group!==$criteria['group'])
		{
			if($this->group==='')
				$this->group=$criteria['group'];
			else if($criteria['group']!=='')
				$this->group.=', '.$criteria['group'];
		}

		if(isset($criteria['having']) && $this->having!==$criteria['having'])
		{
			if($this->having==='')
				$this->having=$criteria['having'];
			else if($criteria['having']!=='')
				$this->having="({$this->having}) AND ({$criteria['having']})";
		}
	}
}


/**
 * CStatRelation represents a statistical relational query.
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id: CActiveRecord.php 2080 2010-05-01 12:40:20Z qiang.xue $
 * @package system.db.ar
 * @since 1.0.4
 */
class CStatRelation extends CBaseActiveRelation
{
	/**
	 * @var string the statistical expression. Defaults to 'COUNT(*)', meaning
	 * the count of child objects.
	 */
	public $select='COUNT(*)';
	/**
	 * @var mixed the default value to be assigned to those records that do not
	 * receive a statistical query result. Defaults to 0.
	 */
	public $defaultValue=0;

	/**
	 * Merges this relation with a criteria specified dynamically.
	 * @param array the dynamically specified criteria
	 * @since 1.0.5
	 */
	public function mergeWith($criteria)
	{
		parent::mergeWith($criteria);

		if(isset($criteria['defaultValue']))
			$this->defaultValue=$criteria['defaultValue'];
	}
}


/**
 * CActiveRelation is the base class for representing active relations that bring back related objects.
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id: CActiveRecord.php 2080 2010-05-01 12:40:20Z qiang.xue $
 * @package system.db.ar
 * @since 1.0
 */
class CActiveRelation extends CBaseActiveRelation
{
	/**
	 * @var string join type. Defaults to 'LEFT OUTER JOIN'.
	 */
	public $joinType='LEFT OUTER JOIN';
	/**
	 * @var string ON clause. The condition specified here will be appended to the joining condition using AND operator.
	 * @since 1.0.2
	 */
	public $on='';
	/**
	 * @var string the alias for the table that this relation refers to. Defaults to null, meaning
	 * the alias will be the same as the relation name.
	 * @since 1.0.1
	 */
	public $alias;
	/**
	 * @var string|array specifies which related objects should be eagerly loaded when this related object is lazily loaded.
	 * For more details about this property, see {@link CActiveRecord::with()}.
	 */
	public $with=array();

	/**
	 * Merges this relation with a criteria specified dynamically.
	 * @param array the dynamically specified criteria
	 * @since 1.0.5
	 */
	public function mergeWith($criteria)
	{
		parent::mergeWith($criteria);

		if(isset($criteria['joinType']))
			$this->joinType=$criteria['joinType'];

		if(isset($criteria['on']) && $this->on!==$criteria['on'])
		{
			if($this->on==='')
				$this->on=$criteria['on'];
			else if($criteria['on']!=='')
				$this->on="({$this->on}) AND ({$criteria['on']})";
		}

		if(isset($criteria['with']))
			$this->with=$criteria['with'];

		if(isset($criteria['alias']))
			$this->alias=$criteria['alias'];

		if(isset($criteria['together']))
			$this->together=$criteria['together'];
	}
}


/**
 * CBelongsToRelation represents the parameters specifying a BELONGS_TO relation.
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id: CActiveRecord.php 2080 2010-05-01 12:40:20Z qiang.xue $
 * @package system.db.ar
 * @since 1.0
 */
class CBelongsToRelation extends CActiveRelation
{
}


/**
 * CHasOneRelation represents the parameters specifying a HAS_ONE relation.
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id: CActiveRecord.php 2080 2010-05-01 12:40:20Z qiang.xue $
 * @package system.db.ar
 * @since 1.0
 */
class CHasOneRelation extends CActiveRelation
{
}


/**
 * CHasManyRelation represents the parameters specifying a HAS_MANY relation.
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id: CActiveRecord.php 2080 2010-05-01 12:40:20Z qiang.xue $
 * @package system.db.ar
 * @since 1.0
 */
class CHasManyRelation extends CActiveRelation
{
	/**
	 * @var integer limit of the rows to be selected. It is effective only for lazy loading this related object. Defaults to -1, meaning no limit.
	 */
	public $limit=-1;
	/**
	 * @var integer offset of the rows to be selected. It is effective only for lazy loading this related object. Defaults to -1, meaning no offset.
	 */
	public $offset=-1;
	/**
	 * @var boolean whether this table should be joined with the primary table.
	 * When setting this property to be false, the table associated with this relation will
	 * appear in a separate JOIN statement. Defaults to true, meaning the table will be joined
	 * together with the primary table. Note that in version 1.0.x, the default value of this property was false.
	 */
	public $together=true;
	/**
	 * @var string the name of the column that should be used as the key for storing related objects.
	 * Defaults to null, meaning using zero-based integer IDs.
	 * @since 1.0.7
	 */
	public $index;

	/**
	 * Merges this relation with a criteria specified dynamically.
	 * @param array the dynamically specified criteria
	 * @since 1.0.5
	 */
	public function mergeWith($criteria)
	{
		parent::mergeWith($criteria);
		if(isset($criteria['limit']) && $criteria['limit']>0)
			$this->limit=$criteria['limit'];

		if(isset($criteria['offset']) && $criteria['offset']>=0)
			$this->offset=$criteria['offset'];

		if(isset($criteria['index']))
			$this->index=$criteria['index'];
	}
}


/**
 * CManyManyRelation represents the parameters specifying a MANY_MANY relation.
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id: CActiveRecord.php 2080 2010-05-01 12:40:20Z qiang.xue $
 * @package system.db.ar
 * @since 1.0
 */
class CManyManyRelation extends CHasManyRelation
{
}


/**
 * CActiveRecordMetaData represents the meta-data for an Active Record class.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id: CActiveRecord.php 2080 2010-05-01 12:40:20Z qiang.xue $
 * @package system.db.ar
 * @since 1.0
 */
class CActiveRecordMetaData
{
	/**
	 * @var CDbTableSchema the table schema information
	 */
	public $tableSchema;
	/**
	 * @var array table columns
	 */
	public $columns;
	/**
	 * @var array list of relations
	 */
	public $relations=array();
	/**
	 * @var array attribute default values
	 */
	public $attributeDefaults=array();

	private $_model;

	/**
	 * Constructor.
	 * @param CActiveRecord the model instance
	 */
	public function __construct($model)
	{
		$this->_model=$model;

		$tableName=$model->tableName();
		if(($table=$model->getDbConnection()->getSchema()->getTable($tableName))===null)
			throw new CDbException(Yii::t('yii','The table "{table}" for active record class "{class}" cannot be found in the database.',
				array('{class}'=>get_class($model),'{table}'=>$tableName)));
		if($table->primaryKey===null)
			$table->primaryKey=$model->primaryKey();
		$this->tableSchema=$table;
		$this->columns=$table->columns;

		foreach($table->columns as $name=>$column)
		{
			if(!$column->isPrimaryKey && $column->defaultValue!==null)
				$this->attributeDefaults[$name]=$column->defaultValue;
		}

		foreach($model->relations() as $name=>$config)
		{
			$this->addRelation($name,$config);
		}
	}

	/**
	 * Adds a relation.
	 *
	 * $config is an array with three elements:
	 * relation type, the related active record class and the foreign key.
	 *
	 * @throws CDbException
	 * @param string $name Name of the relation.
	 * @param array $config Relation parameters.
     * @return void
	 * @since 1.1.2
	 */
	public function addRelation($name,$config)
	{
		if(isset($config[0],$config[1],$config[2]))  // relation class, AR class, FK
			$this->relations[$name]=new $config[0]($name,$config[1],$config[2],array_slice($config,3));
		else
			throw new CDbException(Yii::t('yii','Active record "{class}" has an invalid configuration for relation "{relation}". It must specify the relation type, the related active record class and the foreign key.', array('{class}'=>get_class($this->_model),'{relation}'=>$name)));
	}

	/**
	 * Checks if there is a relation with specified name defined.
	 *
	 * @param string $name Name of the relation.
	 * @return boolean
	 * @since 1.1.2
	 */
	public function hasRelation($name)
	{
		return isset($this->relations[$name]);
	}

	/**
	 * Deletes a relation with specified name.
	 *
	 * @param string $name
	 * @return void
	 * @since 1.1.2
	 */
	public function removeRelation($name)
	{
		unset($this->relations[$name]);
	}
}
