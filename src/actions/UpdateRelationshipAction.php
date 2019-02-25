<?php
/**
 * @author Anton Tuyakhov <atuyakhov@gmail.com>
 */

namespace tuyakhov\jsonapi\actions;

use yii\data\ActiveDataProvider;
use yii\db\BaseActiveRecord;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\ForbiddenException;
use Yii;

/**
 * UpdateRelationshipAction implements the API endpoint for updating relationships.
 * @link http://jsonapi.org/format/#crud-updating-relationships
 */
class UpdateRelationshipAction extends Action
{
    /**
     * Update of relationships independently.
     * @param string $id an ID of the primary resource
     * @param string $name a name of the related resource
     * @return ActiveDataProvider|BaseActiveRecord
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     */
    public function run($id, $name)
    {
        /** @var BaseActiveRecord $model */
        $model = $this->findModel($id);

        $related = $model->getRelation($name);

        $request = Yii::$app->request;
        $bodyParams = $request->getBodyParams();
        
        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $model, $name);
        }

        if ($request->isPatch && $related->multiple) {
            $this->unlinkAllRelationship($model, $name);
        }

        $this->linkRelationships($model, [$name => $bodyParams]);
        if ($related->multiple) {
            return new ActiveDataProvider([
                'query' => $related
            ]);
        } else {
            return $related->one();
        }
    }
}