<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class GroupsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $currentUser = Craft::$app->getUser()->getIdentity();
        $requiredPermission = in_array($action->id, ['delete'], true)
            ? 'menuBuilder:delete'
            : 'menuBuilder:create';

        if (!$currentUser || (!$currentUser->admin && !$currentUser->can($requiredPermission))) {
            throw new ForbiddenHttpException('You are not permitted to manage navigation groups.');
        }

        return true;
    }

    public function actionEdit(?int $groupId = null, ?MenuBuilderGroup $group = null): Response
    {
        if ($group === null) {
            if ($groupId !== null) {
                $group = MenuBuilder::getInstance()->groups->getById($groupId);

                if (!$group) {
                    throw new NotFoundHttpException('Navigation group not found.');
                }
            } else {
                $group = new MenuBuilderGroup();
            }
        }

        return $this->renderTemplate('menu-builder/groups/_edit', [
            'group' => $group,
            'isNew' => $group->id === null,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $groupId = (int)$request->getBodyParam('groupId', 0);
        $group = $groupId ? MenuBuilder::getInstance()->groups->getById($groupId) : new MenuBuilderGroup();

        if (!$group) {
            throw new NotFoundHttpException('Navigation group not found.');
        }

        $group->name = $this->bodyString('name');
        $group->handle = $this->bodyString('handle');
        $description = $request->getBodyParam('description');
        $group->description = is_scalar($description) ? (string)$description : null;
        $group->enabled = (bool)$request->getBodyParam('enabled', false);
        $group->cssClass = $this->bodyString('cssClass') ?: null;
        $maxDepth = $request->getBodyParam('maxDepth');
        $group->maxDepth = ($maxDepth !== null && $maxDepth !== '') ? (int)$maxDepth : null;
        $group->htmlAttributes = $this->parseAttributeLines($this->bodyString('htmlAttributes'));

        if (!MenuBuilder::getInstance()->groups->save($group)) {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'Couldn’t save navigation group.'));

            return $this->asModelFailure($group, Craft::t('menu-builder', 'Couldn’t save navigation group.'), 'group');
        }

        Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Navigation group saved.'));

        return $this->redirectToPostedUrl($group, UrlHelper::cpUrl('menu-builder/' . $group->handle));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $success = MenuBuilder::getInstance()->groups->deleteById($id);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $success
                ? $this->asSuccess()
                : $this->asFailure(Craft::t('menu-builder', 'Couldn’t delete that navigation group.'));
        }

        if ($success) {
            Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Navigation group deleted.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'Couldn’t delete that navigation group.'));
        }

        return $this->redirect(UrlHelper::cpUrl('menu-builder'));
    }

    /** Parses `key: value` per-line input into an attributes array. */
    private function parseAttributeLines(string $input): array
    {
        $attributes = [];

        foreach (explode("\n", $input) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));

            if ($key !== '') {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    private function bodyString(string $name, string $default = ''): string
    {
        $value = Craft::$app->getRequest()->getBodyParam($name, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}
