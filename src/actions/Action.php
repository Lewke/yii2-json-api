<?php
/**
 * @author Anton Tuyakhov <atuyakhov@gmail.com>
 */

namespace tuyakhov\jsonapi\actions;

use tuyakhov\jsonapi\ResourceInterface;
use tuyakhov\jsonapi\Inflector;
use yii\db\ActiveRecordInterface;
use yii\db\BaseActiveRecord;
use yii\helpers\ArrayHelper;
use yii\web\ForbiddenHttpException;
use yii\web\ServerErrorHttpException;
use yii\db\Query;
use yii\helpers\StringHelper;
use Yii;

class Action extends \yii\rest\Action
{
    /**
     * Links the relationships with primary model.
     * @var callable
     */
    public $linkRelationships;

    /**
     * Unlinks the relationships with primary model.
     * @var callable
     */ 
    public $unlinkRelationships;

    /**
     * @var bool Weather allow to do a full replacement of a to-many relationship
     */
    public $allowFullReplacement = true;

    /**
     * Links the relationships with primary model.
     * @param $model ActiveRecordInterface
     * @param array $data relationship links
     */
    protected function linkRelationships($model, array $data = [])
    {
        if ($this->linkRelationships !== null) {
            call_user_func($this->linkRelationships, $this, $model, $data);
            return;
        }

        if (!$model instanceof ResourceInterface) {
            return;
        }

        foreach ($data as $name => $relationship) {
            $related = $model->getRelation($name);

            $name = StringHelper::basename($related->modelClass);
            $relationships = ArrayHelper::getValue($relationship, $name, []);
            $ids = ArrayHelper::getColumn($relationships, 'id');

            if ($related->multiple && !$this->allowFullReplacement) {
                throw new ForbiddenHttpException();
            }

            if (!$related->multiple) {
                $this->linkOne($model, $name, $related, $ids);
            } else if (!$related->via) {
                $this->linkMany($model, $name, $related, $ids);
            } else if (is_array($related->via)) {
                $this->linkManyVia($model, $name, $related, $ids);
            } else {
                $this->linkManyViaTable($model, $name, $related, $ids);
            }
        }
        $model->refresh();
    }

    protected function linkOne($model, $name, $related, $ids)
    {
        $rhs = reset($related->link);
        $model->$rhs = reset($ids);
        if (!$model->save()) {
            throw new ServerErrorHttpException("failed to save a relationship");
        }
    }

    protected function linkMany($model, $name, $related, $ids)
    {
        $rhs = array_keys($related->link)[0];
        $records = $related->modelClass::findAll($ids);

        foreach ($records as $record) {
            $record->$rhs = $model->id;
            if (!$record->save()) {
                throw new ServerErrorHttpException("failed to save a relationship");
            }
        }
    }

    protected function linkManyVia($model, $name, $related, $ids)
    {
        $viaRelation = $related->via[1];
        $rhs = reset($related->link);
        $lhs = array_keys($viaRelation->link)[0];

        foreach ($ids as $id) {
            $record = new $viaRelation->modelClass([
                $rhs => $id,
                $lhs => $model->id
            ]);
            if (!$record->save()) {
                throw new ServerErrorHttpException("failed to save a relationship");
            }
        }
    }

    protected function linkManyViaTable($model, $name, $related, $ids)
    {
        $viaRelation = $related->via;
        $rhs = reset($related->link);
        $lhs = array_keys($viaRelation->link)[0];
        $tableName = $related->via->from[0];

        foreach ($ids as $id) {
            $command = Yii::$app->db->createCommand()->upsert($tableName, [
                $rhs => $id,
                $lhs => $model->id
            ], false);
            if ($command->execute() === false) {
                throw new ServerErrorHttpException('Failed to delete a relationship element.');
            }
        }
    }

    /**
     * Removes all relationship records for the given relationship
     * @param $model ActiveRecordInterface
     * @param string $name relation name to unlink
     */
    public function unlinkAllRelationship($model, $name)
    {
        $related = $model->getRelation($name);

        if (!$related->multiple) {
            $this->unlinkOne($model, $name, $related, []);
        } else if (!$related->via) {
            $this->unlinkMany($model, $name, $related, []);
        } else if (is_array($related->via)) {
            $this->unlinkManyVia($model, $name, $related, []);
        } else {
            $this->unlinkManyViaTable($model, $name, $related, []);
        }
        $model->refresh();
    }

    /**
     * Removes the relationships from primary model.
     * @param $model ActiveRecordInterface
     * @param array $data relationship links
     */
    protected function unlinkRelationships($model, array $data = [])
    {
        if ($this->unlinkRelationships !== null) {
            call_user_func($this->unlinkRelationships, $this, $model, $data);
            return;
        }

        foreach ($data as $name => $relationship) {
            /** @var $related ActiveRelationTrait */
            $related = $model->getRelation($name);

            $name = Inflector::type2form($name);
            $relationships = ArrayHelper::getValue($relationship, $name, []);
            $ids = ArrayHelper::getColumn($relationships, 'id');

            if (!$related->multiple) {
                $this->unlinkOne($model, $name, $related, $ids);
            } else if (!$related->via) {
                $this->unlinkMany($model, $name, $related, $ids);
            } else if (is_array($related->via)) {
                $this->unlinkManyVia($model, $name, $related, $ids);
            } else {
                $this->unlinkManyViaTable($model, $name, $related, $ids);
            }
        }
        $model->refresh();
    }

    protected function unlinkOne($name, $related, $ids)
    {
        $foreignKey = reset($related->link);
        static::isColumnNullable(get_class($this), $foreignKey);

        $this->$foreignKey = null;
        if (!$this->save()) {
            throw new ServerErrorHttpException('Failed to delete a relationship element.');
        }
    }

    protected function unlinkMany($name, $related, $ids)
    {
        $rhsLink = reset($related->link);
        $lhsLink = array_keys($related->link)[0];
        static::isColumnNullable($related->modelClass, $lhsLink);

        $records = $related->andFilterWhere([
            $rhsLink => $ids
        ])->all();
   
        foreach ($records as $record) {
            $record->$lhsLink = null;
            if (!$record->save()) {
                throw new ServerErrorHttpException('Failed to delete a relationship element.');
            }
        }
    }

    protected function unlinkManyVia($name, $related, $ids)
    {
        $viaRelation = $related->via[1];
        $rhsLink = reset($related->link);
        $lhsLink = array_keys($viaRelation->link)[0];

        $viaRecords = $viaRelation->modelClass::find()->andFilterWhere([
            $rhsLink => $ids,
            $lhsLink => $this->getPrimaryKey()
        ]);

        foreach ($viaRecords->all() as $record) {
            if ($record->delete() === false) {
                throw new ServerErrorHttpException('Failed to delete a relationship element.');
            }
        }
    }

    protected function unlinkManyViaTable($name, $related, $ids)
    {
        $rhsLink = reset($related->link);
        $lhsLink = array_keys($related->via->link)[0];
        $tableName = $related->via->from[0];

        $viaTableRecords = new Query();
        $viaTableRecords->from($tableName)->andFilterWhere([
            $rhsLink => $ids,
            $lhsLink => $this->getPrimaryKey()
        ]);

        foreach ($viaTableRecords->all() as $record) {
            $command = Yii::$app->db->createCommand()->delete($tableName, $record);
            if ($command->execute() === false) {
                throw new ServerErrorHttpException('Failed to delete a relationship element.');
            }
        }
    }
    
    protected static function isColumnNullable($modelClass, $columnName)
    {
        if (!$modelClass::getTableSchema()->getColumn($columnName)->allowNull) {
            throw new ForbiddenHttpException();
        }
    }
}