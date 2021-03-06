<?php namespace Way\Tests;

use \Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

class ModelNotFoundException extends \Exception {}

class Factory {

    /**
     * Model instance
     */
    protected $class;

    /**
     * Pluralized form of classname
     *
     * @var string
     */
    protected $tableName;

    /**
     * DB Layer
     *
     * @var Illuminate\Database\DatabaseManager
     */
    protected $db;

    /**
     * Remembers table fields for factories
     *
     * @var array
     */
    protected $columns;

    /**
     * Whether models are being
     * saved to the DB
     *
     * @var boolean
     */
    protected static $isSaving = false;

    /**
     * Stores the namespace root for the class
     *
     * @var string
     */
    protected static $rootNamespace;

    /**
     * For retrieving dummy data
     *
     * @var DataStore
     */
    protected $dataStore;


    /**
     * @param DatabaseManager $db
     * @param DataStore       $dataStore
     */
    public function __construct(DatabaseManager $db = null, DataStore $dataStore = null)
    {
        $this->db = $db ?: \App::make('db');
        $this->dataStore = $dataStore ?: new DataStore;
    }

    /**
     * Create a factory AND save it to the DB.
     *
     * @param  string $class
     * @param  array  $columns
     * @return boolean
     */
    public static function create($class, array $columns = array())
    {
        static::$isSaving = true;

        static::setRootNamespace($class);

        $instance = static::make($class, $columns);

        $instance->save();

        return $instance;
    }

    /**
     * Create factory and return its attributes as an array
     *
     * @param  string $class
     * @param  array  $columns
     * @return array
     */
    public static function attributesFor($class, array $columns = array())
    {
        return static::make($class, $columns)->toArray();
    }

    /**
     * Create a new factory. Factory::post() is equivalent
     * to Factory::make('Post'). Use this method when you need to
     * specify a namespace: Factory::make('Models\Post');
     *
     * You can also override fields. This is helpful for
     * testing validations: Factory::make('Post', ['title' => null])
     *
     * @param  $class
     * @param  array $columns Overrides
     * @return object
     */
    public static function make($class, $columns = array())
    {
        static::$isSaving = false;

        $instance = new static;

        return $instance->fire($class, $columns);
    }

    /**
     * Saves the root namespace for a class
     *
     * @param $class
     */
    protected static function setRootNamespace($class)
    {
        $pos = strrpos($class, '\\');
        static::$rootNamespace = substr($class, 0, $pos);
    }

    /**
     * Set dummy data on fields
     *
     * @param $class Name of class to create factory for
     * @param $overrides
     *
     * @return object
     */
    public function fire($class, array $overrides = array())
    {
        $this->class = $this->createModel($class);
        $this->tableName = $this->class->getTable();

        // First, we dynamically fetch the fields for the table
        $columns = $this->getColumns($this->tableName);

        // Then, set column values.
        $this->setColumns($columns, $overrides);

        // And then return the new class
        return $this->class;
    }

    /**
     * Initialize the given model
     *
     * @param  string $class
     * @return object
     * @throws ModelNotFoundException
     */
    protected function createModel($class)
    {
        $class = Str::studly($class);

        if (class_exists($class))
            return new $class;

        throw new ModelNotFoundException;
    }

    /**
     * Is the model namespaced?
     *
     * @param  string $class
     * @return boolean
     */
    protected function isNamespaced($class)
    {
        return str_contains($class, '\\');
    }

    /**
     * Fetch the table fields for the class.
     *
     * @param  string $tableName
     * @return array
     */
    public function getColumns($tableName)
    {
        // We only want to fetch the table details
        // once. We'll store these fields with a
        // $columns property for future fetching.
        if (isset($this->columns[$this->tableName]))
        {
            return $this->columns[$this->tableName];
        }

        // This will only run the first time the factory is created.
        return $this->columns[$this->tableName] = $this->db->getDoctrineSchemaManager()->listTableDetails($tableName)->getColumns();
    }

    /**
     * Set fields for object
     *
     * @param array $columns
     */
    protected function setColumns(Array $columns, Array $overrides)
    {
        $processed_keys = [];

        //loop through table columns and fill with stub or override value
        foreach($columns as $key => $col)
        {
            //create relationship
            if ($relation = $this->hasForeignKey($key))
            {
                $this->class->$key = $this->createRelationship($relation);
                $processed_keys[] = $key;
                continue;
            }
            //fill with override
            if (array_key_exists($key, $overrides))
            {
                $this->class->$key = $overrides[$key];
                $processed_keys[] = $key;
                continue; 
            }
            //fill with stub
            $this->class->$key = $this->setColumnWithStub($key, $col);
            $processed_keys[] = $key;
        }

        //in case we don't have table columns (ie table wasnt migrated) at least ensure overrides are set
        foreach ($overrides as $override_key => $override_value)
        {
            if (!in_array($override_key, $processed_keys))
            {
                 $this->class->$override_key = $override_value;
            }
        }
    }

    /**
     * Set single column with stub value
     *
     * @param string $name
     * @param string $col
     * @throws \Exception
     */
    protected function setColumnWithStub($name, $col)
    {
        if ($name === 'id') return;

        $method = $this->getFakeMethodName($name, $col);

        if (method_exists($this->dataStore, $method))
        {
            return $this->dataStore->{$method}();
        }

        throw new \Exception('Could not calculate correct fixture method.');
    }

    /**
     * Build the faker method
     *
     * @param  string $field
     * @param string $col
     *
     * @return string
     */
    protected function getFakeMethodName($field, $col)
    {
        // We'll first try to get special fields
        // That way, we can insert appropriate
        // values, like an email or first name
        $method = $this->checkForSpecialField($field);

        // If we couldn't, we'll instead grab
        // the datatype for the field, and
        // generate a value to fit that.
        if (!$method) $method = $this->getDataType($col);

        // Build the method name
        return 'get' . ucwords($method);
    }

    /**
     * Search for special field names
     *
     * @param  string $field
     * @return mixed
     */
    protected function checkForSpecialField($field)
    {
        $special = array(
            'name', 'email', 'phone',
            'age', 'address', 'city',
            'state', 'zip', 'street',
            'website', 'title'
        );

        return in_array($field, $special) ? $field : false;
    }

    /**
     * Is the field a foreign key?
     *
     * @param  string $field
     * @return mixed
     */
    protected function hasForeignKey($field)
    {
        // Do we need to create a relationship?
        // Look for a field, like author_id or author-id
        if (static::$isSaving and preg_match('/([A-z]+)[-_]id$/i', $field, $matches))
        {
            // We'll assume that this is a true foreign key, if the field is foo_id
            // and a corresponding Foo or Namespace\Foo class exists
            if ($this->confirmForeignKeyReferencesRelationship($matches[1]))
            {
                return $matches[1];
            }
        }

        return false;
    }

    /**
     * Create a new factory and return its id
     *
     * @param  string $class
     * @return id
     */
    protected function createRelationship($class)
    {
        return static::create(static::$rootNamespace."\\$class")->id;
    }

    /**
     * Calculate the data type for the field
     *
     * @param  string $col
     * @return string
     */
    public function getDataType($col)
    {
        return $col->getType()->getName();
    }


    /**
     * Handle dynamic factory creation calls,
     * like Factory::user() or Factory::post()
     *
     * @param  string $class The model to mock
     * @param  array $overrides
     * @return object
     */
    public static function __callStatic($class, $overrides)
    {
        // A litle weird. TODO
        $overrides = isset($overrides[0]) ? $overrides[0] : $overrides;
        $instance = new static;

        return $instance->fire($class, $overrides);
    }

    /**
     * Determines if field_ID refers to a relationship
     *
     * @param $class
     *
     * @return bool
     */
    protected function confirmForeignKeyReferencesRelationship($class)
    {
        return class_exists($class) or class_exists(static::$rootNamespace."\\$class");
    }

}
