<?php

/**
 * @link http://www.letyii.com/
 * @copyright Copyright (c) 2015 Let.,ltd
 * @license https://github.com/letyii/cms/blob/master/LICENSE
 * @author Ngua Go <nguago@let.vn>
 */

namespace letyii\rbacmongodb;

use Yii;
use yii\rbac\BaseManager;
use yii\mongodb\Connection;
use yii\mongodb\Query;
use yii\db\Expression;
use yii\di\Instance;
use yii\base\InvalidCallException;
use yii\base\InvalidParamException;
use yii\rbac\Assignment;
use yii\rbac\Item;
use yii\rbac\Permission;
use yii\rbac\Role;

class MongodbManager extends BaseManager
{

    /**
     * @var Connection|string the DB connection object or the application component ID of the DB connection.
     * After the MongodbManager object is created, if you want to change this property, you should only assign it
     * with a DB connection object.
     */
    public $db = 'mongodb';

    /**
     * @var string the name of the table storing authorization items. Defaults to "auth_item".
     */
    public $itemTable = 'auth_item';

    /**
     * @var string the name of the table storing authorization item hierarchy. Defaults to "auth_item_child".
     */
    public $itemChildTable = 'auth_item_child';
    
    /**
     * @var string the name of the table storing authorization item assignments. Defaults to "auth_assignment".
     */
    public $assignmentTable = 'auth_assignment';

    /**
     * @var string the name of the table storing rules. Defaults to "auth_rule".
     */
    public $ruleTable = 'auth_rule';

    /**
     * God Id always access
     */
    public $god_id = null;
 
    /**
     * Initializes the application component.
     * This method overrides the parent implementation by establishing the database connection.
     */
    public function init() {
        parent::init();
        $this->db = Instance::ensure($this->db, Connection::className());
        $this->db->getCollection($this->itemTable)->createIndex(['name' => 1], ['unique' => true]);
        $this->db->getCollection($this->ruleTable)->createIndex(['name' => 1], ['unique' => true]);
    }

    /**
     * @inheritdoc
     */
    public function checkAccess($userId, $permissionName, $params = []) {
        if (!empty($this->god_id) AND $this->god_id == (string) $userId)
            return true;
        $assignments = $this->getAssignments($userId);
        return $this->checkAccessRecursive($userId, $permissionName, $params, $assignments);
    }
    
    /**
     * Performs access check for the specified user.
     * This method is internally called by [[checkAccess()]].
     * @param string|integer $user the user ID. This should can be either an integer or a string representing
     * the unique identifier of a user. See [[\yii\web\User::id]].
     * @param string $itemName the name of the operation that need access check
     * @param array $params name-value pairs that would be passed to rules associated
     * with the tasks and roles assigned to the user. A param with name 'user' is added to this array,
     * which holds the value of `$userId`.
     * @param Assignment[] $assignments the assignments to the specified user
     * @return boolean whether the operations can be performed by the user.
     */
    protected function checkAccessRecursive($user, $itemName, $params, $assignments) {
        if (($item = $this->getItem($itemName)) === null) {
            return false;
        }

        Yii::trace($item instanceof Role ? "Checking role: $itemName" : "Checking permission: $itemName", __METHOD__);

        if (!$this->executeRule($user, $item, $params)) {
            return false;
        }

        if (isset($assignments[$itemName]) || in_array($itemName, $this->defaultRoles)) {
            return true;
        }

        $parents = (new Query)->select(['parent'])
            ->from($this->itemChildTable)
            ->where(['child' => $itemName])
            ->all($this->db);
        foreach ($parents as $parent) {
            if ($this->checkAccessRecursive($user, $parent['parent'], $params, $assignments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    protected function getItem($name) {
        $row = (new Query)->from($this->itemTable)
            ->where(['name' => $name])
            ->one($this->db);

        if ($row === false) {
            return null;
        }

        if (!isset($row['data']) || ($data = @unserialize($row['data'])) === false) {
            $row['data'] = null;
        }

        return $this->populateItem($row);
    }

    /**
     * @inheritdoc
     */
    protected function addItem($item) {
        $time = time();
        if ($item->createdAt === null) {
            $item->createdAt = $time;
        }
        if ($item->updatedAt === null) {
            $item->updatedAt = $time;
        }
        $this->db->getCollection($this->itemTable)
            ->insert([
                'name' => $item->name,
                'type' => $item->type,
                'description' => $item->description,
                'rule_name' => $item->ruleName,
                'data' => $item->data === null ? null : serialize($item->data),
                'created_at' => $item->createdAt,
                'updated_at' => $item->updatedAt,
            ]);

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function removeItem($item) {
        $this->db->getCollection($this->itemChildTable)->remove(['or', ['parent' => $item->name], ['child' => $item->name]]);
        $this->db->getCollection($this->assignmentTable)->remove(['item_name' => $item->name]);
        $this->db->getCollection($this->itemTable)->remove(['name' => $item->name]);
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function updateItem($name, $item) {
        if ($item->name !== $name) {
            $this->db->getCollection($this->itemChildTable)->update(['parent' => $name], ['parent' => $item->name]);
            $this->db->getCollection($this->itemChildTable)->update(['child' => $name], ['child' => $item->name]);
            $this->db->getCollection($this->assignmentTable)->update(['item_name' => $name], ['item_name' => $item->name]);
        }

        $item->updatedAt = time();

        $this->db->getCollection($this->itemTable)->update(['name' => $name], [
            'name' => $item->name,
            'description' => $item->description,
            'rule_name' => $item->ruleName,
            'data' => $item->data === null ? null : serialize($item->data),
            'updated_at' => $item->updatedAt,
        ]);
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function addRule($rule) {
        $time = time();
        if ($rule->createdAt === null) {
            $rule->createdAt = $time;
        }
        if ($rule->updatedAt === null) {
            $rule->updatedAt = $time;
        }
        $this->db->getCollection($this->ruleTable)
            ->insert([
                'name' => $rule->name,
                'data' => serialize($rule),
                'created_at' => $rule->createdAt,
                'updated_at' => $rule->updatedAt,
            ]);

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function updateRule($name, $rule) {
        if ($rule->name !== $name) {
            $this->db->getCollection($this->itemTable)
                ->update(['rule_name' => $name], ['rule_name' => $rule->name]);
        }

        $rule->updatedAt = time();

        $this->db->getCollection($this->ruleTable)->update(['name' => $name], [
            'name' => $rule->name,
            'data' => serialize($rule),
            'updated_at' => $rule->updatedAt,
        ]);

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function removeRule($rule) {
        $this->db->getCollection($this->itemTable)->remove(['rule_name' => $rule->name]);
        $this->db->getCollection($this->ruleTable)->remove(['name' => $rule->name]);
        return true;
    }
    
    /**
     * @inheritdoc
     */
    protected function getItems($type) {
        $query = (new Query)
            ->from($this->itemTable)
            ->where(['type' => $type]);

        $items = [];
        foreach ($query->all($this->db) as $row) {
            $items[$row['name']] = $this->populateItem($row);
        }

        return $items;
    }

    /**
     * Populates an auth item with the data fetched from database
     * @param array $row the data from the auth item table
     * @return Item the populated auth item instance (either Role or Permission)
     */
    protected function populateItem($row) {
        $class = $row['type'] == Item::TYPE_PERMISSION ? Permission::className() : Role::className();

        if (!isset($row['data']) || ($data = @unserialize($row['data'])) === false) {
            $data = null;
        }

        return new $class([
            'name' => $row['name'],
            'type' => $row['type'],
            'description' => $row['description'],
            'ruleName' => $row['rule_name'],
            'data' => $data,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ]);
    }
    
    /**
     * @inheritdoc
     */
    public function getRolesByUser($userId) {
        if (empty($userId)) {
            return [];
        }

        // Get Item name
        $itemName = [];
        $query = (new Query())->select(['item_name'])
            ->from($this->assignmentTable)
            ->where(['user_id' => (string)$userId]);
        foreach ($query->all($this->db) as $row) {
            $itemName[] = $row['item_name'];
        }
        
        // Get Roles
        $roles = [];
        $query = (new Query)->from($this->itemTable)
            ->where(['name' => $itemName]);
        foreach ($query->all($this->db) as $row) {
            $roles[$row['name']] = $this->populateItem($row);
        }
        
        return $roles;
    }
    
    /**
     * @inheritdoc
     */
    public function getPermissionsByRole($roleName) {
        $childrenList = $this->getChildrenList();
        $result = [];
        $this->getChildrenRecursive($roleName, $childrenList, $result);
        if (empty($result)) {
            return [];
        }
        $query = (new Query)->from($this->itemTable)->where([
            'type' => Item::TYPE_PERMISSION,
            'name' => array_keys($result),
        ]);
        $permissions = [];
        foreach ($query->all($this->db) as $row) {
            $permissions[$row['name']] = $this->populateItem($row);
        }
        return $permissions;
    }

    /**
     * @inheritdoc
     */
    public function getPermissionsByUser($userId) {
        if (empty($userId)) {
            return [];
        }

        $query = (new Query)->select('item_name')
            ->from($this->assignmentTable)
            ->where(['user_id' => (string)$userId]);

        $childrenList = $this->getChildrenList();
        $result = [];
        foreach ($query->all($this->db) as $role) {
            $this->getChildrenRecursive($roleName['item_name'], $childrenList, $result);
        }

        if (empty($result)) {
            return [];
        }

        $query = (new Query)->from($this->itemTable)->where([
            'type' => Item::TYPE_PERMISSION,
            'name' => array_keys($result),
        ]);
        $permissions = [];
        foreach ($query->all($this->db) as $row) {
            $permissions[$row['name']] = $this->populateItem($row);
        }
        return $permissions;
    }

    /**
     * Returns the children for every parent.
     * @return array the children list. Each array key is a parent item name,
     * and the corresponding array value is a list of child item names.
     */
    protected function getChildrenList() {
        $query = (new Query)->from($this->itemChildTable);
        $parents = [];
        foreach ($query->all($this->db) as $row) {
            $parents[$row['parent']][] = $row['child'];
        }
        return $parents;
    }

    /**
     * Recursively finds all children and grand children of the specified item.
     * @param string $name the name of the item whose children are to be looked for.
     * @param array $childrenList the child list built via [[getChildrenList()]]
     * @param array $result the children and grand children (in array keys)
     */
    protected function getChildrenRecursive($name, $childrenList, &$result) {
        if (isset($childrenList[$name])) {
            foreach ($childrenList[$name] as $child) {
                $result[$child] = true;
                $this->getChildrenRecursive($child, $childrenList, $result);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getRule($name) {
        $row = (new Query)->select(['data'])
            ->from($this->ruleTable)
            ->where(['name' => $name])
            ->one($this->db);
        return $row === false ? null : @unserialize($row['data']);
    }

    /**
     * @inheritdoc
     */
    public function getRules() {
        $query = (new Query)->from($this->ruleTable);

        $rules = [];
        foreach ($query->all($this->db) as $row) {
            $rules[$row['name']] = @unserialize($row['data']);
        }

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getAssignment($roleName, $userId) {
        if (empty($userId)) {
            return null;
        }

        $row = (new Query)->from($this->assignmentTable)
            ->where(['user_id' => (string)$userId, 'item_name' => $roleName])
            ->one($this->db);

        if ($row === false) {
            return null;
        }
        
        return new Assignment([
            'userId' => $row['user_id'],
            'roleName' => $row['item_name'],
            'createdAt' => $row['created_at'],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getAssignments($userId) {
        if (empty($userId)) {
            return [];
        }

        $query = (new Query)
            ->from($this->assignmentTable)
            ->where(['user_id' => (string)$userId]);

        $assignments = [];
        foreach ($query->all($this->db) as $row) {
            $assignments[$row['item_name']] = new Assignment([
                'userId' => $row['user_id'],
                'roleName' => $row['item_name'],
                'createdAt' => $row['created_at'],
            ]);
        }

        return $assignments;
    }

    /**
     * Return all user assigment information for the specified role
     * @param string $roleName the role name
     * @return The assignment information. An empty array will be returned if there is no user assigned to the role.
     */
    public function getRoleAssigments($roleName) {
        $query = (new Query)->from($this->assignmentTable)
            ->where(['item_name' => $roleName]);

        $assignments = [];
        foreach ($query->all($this->db) as $row) {
            $assignments[$row['user_id']] = new Assignment([
                'userId' => $row['user_id'],
                'roleName' => $row['item_name'],
                'createdAt' => $row['created_at'],
            ]);
        }

        return $assignments;
    }

    /**
     * @inheritdoc
     */
    public function addChild($parent, $child) {
        if ($parent->name === $child->name) {
            throw new InvalidParamException("Cannot add '{$parent->name}' as a child of itself.");
        }

        if ($parent instanceof Permission && $child instanceof Role) {
            throw new InvalidParamException("Cannot add a role as a child of a permission.");
        }

        if ($this->detectLoop($parent, $child)) {
            throw new InvalidCallException("Cannot add '{$child->name}' as a child of '{$parent->name}'. A loop has been detected.");
        }

        $this->db->getCollection($this->itemChildTable)
            ->insert(['parent' => $parent->name, 'child' => $child->name]);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function removeChild($parent, $child) {
        $parentName = is_object($parent) ? $parent->name : $parent;
        $childName = is_object($child) ? $child->name : $child;
        return $this->db->getCollection($this->itemChildTable)
            ->remove(['parent' => $parentName, 'child' => $childName]) === true;
    }

    /**
     * @inheritdoc
     */
    public function removeChildren($parent) {
        $parentName = is_object($parent) ? $parent->name : $parent;
        return $this->db->getCollection($this->itemChildTable)
            ->remove(['parent' => $parentName]) === true;
    }
    
    public function removeChildrenByType($parent, $type = null) {
        if (empty($type))
            return FALSE;
        
        $parentName = is_object($parent) ? $parent->name : $parent;
        
        // Get all children, included role and permission
        $children = (new Query)
            ->select(['child'])
            ->from($this->itemChildTable)
            ->where(['parent'=>$parentName])
            ->all($this->db);
        if ($children) 
            $children = \yii\helpers\ArrayHelper::map ($children, '_id', 'child');
        else
            $children = [];
        
        // Get all items by type
        $items = (new Query)
            ->select(['name'])
            ->from($this->itemTable)
            ->where(['type' => $type])
            ->all($this->db);
        
        $removeChildren = [];
        foreach ($items as $item) {
            if (in_array($item['name'], $children)) {
                $removeChildren[] = $item['name'];
            }
        }
        
        // Delete all child in parent
        foreach ($removeChildren as $removeChild) {
            $this->removeChild($parentName, $removeChild);
        }
        
//        return $this->db->getCollection($this->itemChildTable)
//            ->remove(['parent' => $parent->name]) === true;
    }
    
    /**
     * @inheritdoc
     */
    public function hasChild($parent, $child) {
        return (new Query)
            ->from($this->itemChildTable)
            ->where(['parent' => $parent->name, 'child' => $child->name])
            ->one($this->db) !== false;
    }

    /**
     * @inheritdoc
     */
    public function getChildren($name) {
        $names = array_map(create_function('$v', 'return $v["child"];'), (new Query)
            ->select(['child'])
            ->from($this->itemChildTable)
            ->where(['parent'=>$name])
            ->all($this->db));

        $query = (new Query)
            ->select(['name', 'type', 'description', 'rule_name', 'data', 'created_at', 'updated_at'])
            ->from($this->itemTable)
            ->where(['name'=>$names]);

        $children = [];
        foreach ($query->all($this->db) as $row) {
            $children[$row['name']] = $this->populateItem($row);
        }

        return $children;
    }
    
    /**
     * Checks whether there is a loop in the authorization item hierarchy.
     * @param Item $parent the parent item
     * @param Item $child the child item to be added to the hierarchy
     * @return boolean whether a loop exists
     */
    protected function detectLoop($parent, $child) {
        if ($child->name === $parent->name) {
            return true;
        }
        foreach ($this->getChildren($child->name) as $grandchild) {
            if ($this->detectLoop($parent, $grandchild)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function assign($role, $userId) {
        $assignment = new Assignment([
            'userId' => (string)$userId,
            'roleName' => $role->name,
            'createdAt' => time(),
        ]);
        
        $assign = (new Query())->from($this->assignmentTable)->where(['user_id' => $assignment->userId, 'item_name' => $assignment->roleName])->one($this->db);
        
        if (!$assign) {
            $this->db->getCollection($this->assignmentTable)
                ->insert([
                    'user_id' => $assignment->userId,
                    'item_name' => $assignment->roleName,
                    'created_at' => $assignment->createdAt,
                ]);
        }

        return $assignment;
    }
    
    /**
     * @inheritdoc
     */
    public function revoke($role, $userId) {
        $roleName = is_object($role) ? $role->name : $role;
        if (empty($userId)) {
            return false;
        }

        return $this->db->getCollection($this->assignmentTable)->remove(['user_id' => (string)$userId, 'item_name' => $roleName]) === true;
    }

    /**
     * @inheritdoc
     */
    public function revokeAll($userId) {
        if (empty($userId)) {
            return false;
        }

        return $this->db->getCollection($this->assignmentTable)->remove(['user_id' => (string)$userId]) === true;
    }

    /**
     * @inheritdoc
     */
    public function removeAll() {
        $this->removeAllAssignments();
        $this->db->getCollection($this->itemChildTable)->drop();
        $this->db->getCollection($this->itemTable)->drop();
        $this->db->getCollection($this->ruleTable)->drop();
    }

    /**
     * @inheritdoc
     */
    public function removeAllPermissions() {
        $this->removeAllItems(Item::TYPE_PERMISSION);
    }

    /**
     * @inheritdoc
     */
    public function removeAllRoles() {
        $this->removeAllItems(Item::TYPE_ROLE);
    }

    /**
     * Removes all auth items of the specified type.
     * @param integer $type the auth item type (either Item::TYPE_PERMISSION or Item::TYPE_ROLE)
     */
    protected function removeAllItems($type) {
        $names = [];
        $query = (new Query)
            ->select(['name'])
            ->from($this->itemTable)
            ->where(['type' => $type]);
        foreach ($query->all($this->db) as $row) {
            $names[] = $row['name'];
        }

        if (empty($names)) {
            return;
        }
        $key = $type == Item::TYPE_PERMISSION ? 'child' : 'parent';
        $this->db->getCollection($this->itemChildTable)->remove([$key => $names]);
        $this->db->getCollection($this->assignmentTable)->remove(['item_name' => $names]);
        $this->db->getCollection($this->itemTable)->remove(['type' => $type]);
    }

    /**
     * @inheritdoc
     */
    public function removeAllRules() {
        $this->db->getCollection($this->itemTable)->update(['ruleName' => null], []);
        $this->db->getCollection($this->ruleTable)->drop();
    }

    /**
     * @inheritdoc
     */
    public function removeAllAssignments() {
        $this->db->getCollection($this->assignmentTable)->drop();
    }
    
    public function checkItemExist($name) {
        if ($this->getItem($name) === null)
            return false;
        else
            return true;
    }
    
    /**
     * Build tree from item table
     * @param string $item
     * @param integer $level
     * @return array
     */
    public function buildTreeRole($item = NULL, $roleList = []) {
        $tree = [];
        $allParents = [];
        if (empty($roleList))
            $roleList = $this->getRoles();
        
        if ($item == NULL) {
            // Get all children
            $childRoles = [];
            $query = (new Query)->select(['child'])
                ->from($this->itemChildTable)
                ->where([]);
            foreach ($query->all($this->db) as $key => $value) {
                $childRoles[] = $value['child'];
            }
            
            // Get all roles
            $query = (new Query)->select(['name'])
                ->from($this->itemTable)
                ->where(['type' => 1]);
            foreach ($query->all($this->db) as $key => $value) {
                if (!in_array($value['name'], $childRoles))
                    $allParents[] = $value;
            }
        }
        if (!empty($allParents)) {
            foreach ($allParents as $parent) {
                $tree[$parent['name']] = [
                    'title' => $roleList[$parent['name']]->description,
                    'items' => $this->buildTreeRole($parent['name'], $roleList),
                ];
            }
        } else {
            // Get
            $childs = (new Query)->select(['child'])
                ->from($this->itemChildTable)
                ->where(['parent' => $item])
                ->all($this->db);
            
            // lấy ra name của các role gán vào mảng mới.
            $checkRole = [];
            foreach ($roleList as $v) {
                $checkRole[] = $v->name;
            }
            
            foreach ($childs as $child) {
                // Nếu item là permission thì bỏ qua.
                if (!in_array($child['child'], $checkRole))
                    continue;
                $tree[$child['child']] = [
                    'title' => $roleList[$child['child']]->description,
                    'items' => $this->buildTreeRole($child['child'], $roleList),
                ];
            }
        }
        return $tree;
    }
}