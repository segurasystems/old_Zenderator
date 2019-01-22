<?php

namespace Zenderator\Components;

use Zenderator\Exception\DBTypeNotTranslatedException;
use Zenderator\Zenderator;

class Column extends Entity
{
    /** @var Model */
    protected $model;
    
    protected $field;
    protected $dbType;
    protected $phpType;
    protected $maxLength;
    protected $isUnsigned = false;
    protected $maxFieldLength;
    protected $maxDecimalPlaces;
    protected $permittedValues;
    protected $defaultValue;
    protected $isAutoIncrement = false;
    protected $isUnique = false;
    /** @var RelatedModel[] */
    protected $relatedObjects = [];
    /** @var RelatedModel[] */
    protected $remoteObjects = [];

    /**
     * @return self
     */
    public static function Factory(Zenderator $zenderator)
    {
        return parent::Factory($zenderator);
    }

    /**
     * @return Model
     */
    public function getModel() : Model
    {
        return $this->model;
    }

    /**
     * @param Model $model
     *
     * @return Column
     */
    public function setModel(Model $model)
    {
        $this->model = $model;
        return $this;
    }

    /**
     * @return bool
     */
    public function isUnsigned(): bool
    {
        return $this->isUnsigned;
    }

    /**
     * @param bool $isUnsigned
     *
     * @return Column
     */
    public function setIsUnsigned(bool $isUnsigned): Column
    {
        $this->isUnsigned = $isUnsigned;
        return $this;
    }

    /**
     * @return bool
     */
    public function isAutoIncrement(): bool
    {
        return $this->isAutoIncrement;
    }

    /**
     * @param bool $isAutoIncrement
     *
     * @return Column
     */
    public function setIsAutoIncrement(bool $isAutoIncrement): Column
    {
        $this->isAutoIncrement = $isAutoIncrement;
        return $this;
    }

    /**
     * @return bool
     */
    public function isUnique(): bool
    {
        return $this->isUnique;
    }

    /**
     * @param bool $isUnique
     *
     * @return Column
     */
    public function setIsUnique(bool $isUnique): Column
    {
        $this->isUnique = $isUnique;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getPhpType()
    {
        return $this->phpType;
    }

    /**
     * @param mixed $phpType
     *
     * @return Column
     */
    public function setPhpType($phpType)
    {
        $this->phpType = $phpType;
        return $this;
    }

    public function getPropertyName()
    {
        return $this->transField2Property->transform($this->getField());
    }

    /**
     * @return mixed
     */
    public function getField()
    {
        return $this->field;
    }

    /**
     * @param mixed $field
     *
     * @return Column
     */
    public function setField($field)
    {
        $this->field = $field;
        return $this;
    }

    public function getPropertyFunction()
    {
        return $this->transCamel2Studly->transform($this->getField());
    }

    /**
     * @return mixed
     */
    public function getMaxDecimalPlaces()
    {
        return $this->maxDecimalPlaces;
    }

    /**
     * @param mixed $maxDecimalPlaces
     *
     * @return Column
     */
    public function setMaxDecimalPlaces($maxDecimalPlaces)
    {
        $this->maxDecimalPlaces = $maxDecimalPlaces;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDefaultValue()
    {
        return $this->defaultValue;
    }

    /**
     * @param mixed $defaultValue
     *
     * @return Column
     */
    public function setDefaultValue($defaultValue)
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getMaxLength()
    {
        return $this->maxLength;
    }

    /**
     * @param mixed $maxLength
     *
     * @return Column
     */
    public function setMaxLength($maxLength)
    {
        $this->maxLength = $maxLength;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getMaxFieldLength()
    {
        return $this->maxFieldLength;
    }

    /**
     * @param mixed $maxFieldLength
     *
     * @return Column
     */
    public function setMaxFieldLength($maxFieldLength)
    {
        $this->maxFieldLength = $maxFieldLength;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDbType()
    {
        return $this->dbType;
    }

    /**
     * @param mixed $dbType
     *
     * @throws DBTypeNotTranslatedException
     *
     * @return Column
     */
    public function setDbType($dbType)
    {
        $this->dbType = $dbType;
        switch ($this->getDbType()) {
            case 'float':
            case 'decimal':
            case 'double':
                $this->setPhpType('float');
                break;
            case 'bit':
            case 'int':
            case 'bigint':
            case 'tinyint':
            case 'smallint':
                $this->setPhpType('int');
                break;
            case 'varchar':
            case 'smallblob':
            case 'blob':
            case 'longblob':
            case 'smalltext':
            case 'text':
            case 'longtext':
            case 'json':
                $this->setPhpType('string');
                break;
            case 'enum':
                $this->setPhpType('string');
                break;
            case 'datetime':
                $this->setPhpType('string');
                break;
            default:
                throw new DBTypeNotTranslatedException("Type not translated: {$this->getDbType()}");
        }

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPermittedValues()
    {
        return $this->permittedValues;
    }

    /**
     * @param mixed $permittedValues
     *
     * @return Column
     */
    public function setPermittedValues($permittedValues)
    {
        $this->permittedValues = $permittedValues;
        return $this;
    }

    /**
     * @param RelatedModel $relatedModel
     *
     * @return $this
     */
    public function addRelatedObject(RelatedModel $relatedModel)
    {
        $this->relatedObjects[] = $relatedModel;
        return $this;
    }

    /**
     * @param RelatedModel $relatedModel
     *
     * @return $this
     */
    public function addRemoteObject(RelatedModel $relatedModel)
    {
        $this->remoteObjects[] = $relatedModel;
        return $this;
    }

    public function hasRelatedObjects() : bool
    {
        return count($this->relatedObjects) > 0;
    }

    public function hasRemoteObjects() : bool
    {
        return count($this->remoteObjects) > 0;
    }

    /**
     * @return RelatedModel[]
     */
    public function getRelatedObjects() : array
    {
        return $this->relatedObjects;
    }

    /**
     * @return RelatedModel[]
     */
    public function getRemoteObjects() : array
    {
        return $this->remoteObjects;
    }
}
