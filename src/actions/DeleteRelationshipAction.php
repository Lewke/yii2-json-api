<?php
/**
 * @author Anton Tuyakhov <atuyakhov@gmail.com>
 */

namespace tuyakhov\jsonapi\actions;

use Yii;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecordInterface;
use yii\db\ActiveRelationTrait;
use yii\db\BaseActiveRecord;
use yii\helpers\ArrayHelper;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Deletes the specified members from a relationship
 * @link http://jsonapi.org/format/#crud-updating-relationships
 */
class DeleteRelationshipAction extends Action
{
    /**
     * @param string $id an ID of the primary resource
     * @param string $name a name of the related resource
     * @return ActiveDataProvider
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     */
    public function run($id, $name)
    {
         /** @var BaseActiveRecord $model */
        $model = $this->findModel($id);

        $related = $model->getRelation($name);

        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $model, $name);
        }

        $this->unlinkRelationships($model, [$name => Yii::$app->getRequest()->getBodyParams()]);


        return new ActiveDataProvider([
            'query' => $related
        ]);
    }
}