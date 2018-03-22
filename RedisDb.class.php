<?php
namespace Common\Api;

abstract class RedisDb
{

    protected $redis = null;

    protected $tableTempla = 'hash_{table}_{pkfield}:{id}'; // 主hash表key模板aaa

    protected $unkTempla = 'zset_{table}_index_unk_{field}:{val}'; // 唯一索引key模板

    protected $numTempla = 'zset_{table}_index_num_{field}'; // 数值索引key模板

    protected $selectedPk = []; // 选中的主hash键列表

    protected $tempZsetKey_end; // 查询时用到的临时zset键名,最终结果集合

    protected $tempZsetKey_mid; // 查询时用到的临时zset键名,中间结果集

    protected $sort = 'asc'; // 排序规则

    protected $pkIndexKey; // 主键字段

    protected $start = 0; // 获取列表时的起始位置

    protected $stop = - 1; // 获取列表时的结束位置

    protected $isCreated = false; // 是否创建过临时集合，为空也标识为创建

    protected $table = '';
    
    /*
     * 定义表名
     * protected $table = 'comment';
     */
    protected $fields = [];
    /*
     * 字段定义
     * protected $fields = array(
     * 'id',
     * 'uid',
     * 'content',
     * 'status',
     * 'recontent',
     * 'create_time',
     * 'good',
     * 'level',
     * 'usercannel'
     * );
     */
    protected $indexs = [];

    protected $pkField = null; // 主键索引字段
    
    /*
     * 索引定义
     * 'unk'是普通索引，'num'是数值索引 'pk'主键索引字段
     *
     * protected $indexs = array(
     * 'id' => 'pk',
     * 'uid' => 'unk',
     * 'status' => 'unk',
     * 'create_time' => 'num',
     * 'good' => 'num',
     * 'level' => 'num',
     * 'usercannel' => 'num'
     * );
     *
     */
    public function __construct ()
    {
        $thinkRedis = new \Think\Cache\Driver\Redis();
        $this->redis = $thinkRedis->handler;
        $uniqid = uniqid();
        $this->tempZsetKey_end = $this->table . '_templa_' . $uniqid . '_end';
        $this->tempZsetKey_mid = $this->table . '_templa_' . $uniqid . '_mid';
        $this->pkIndexKey = $this->table . '_index_pk';
        if (empty($this->fields) || empty($this->table) || empty($this->indexs))
            throw new \Exception('检查表名、字段列表、索引列表');
        $this->pkField = array_search('pk', $this->indexs);
        if ($this->pkField === false)
            throw new \Exception('未设置主键字段pk索引');
        $this->tableTempla = str_replace('{pkfield}', $this->pkField, 
                $this->tableTempla);
    }
    
    /*
     * 插入一条数据
     * @param Array $data 一条记录
     */
    public function insert ($data)
    {
        $this->checkField($data);
        
        $pk = $this->insertRow($data);
        $this->createIndex($data, $pk);
    }
    
    /*
     * 根据主键id删除一条记录
     * @param Int $id
     */
    public function delete ()
    {
        if (! $this->isCreated)
            throw new \Exception('删除必须先设置查询条件');
        $this->getSelected();
        foreach ($this->selectedPk as $pk) {
            
            $data = $this->redis->hgetall($pk);
            
            // 删除主记录
            $this->redis->del($pk);
            
            // 通过这条数据的内容和hash主键删除相应的索引
            $this->delIndex($data, $pk);
        }
        return count($this->selectedPk);
    }
    
    /*
     * 修改选中的主键列表中某个字段值的值
     * @param Array $fields 字段名=>字段值
     */
    public function update ($fields)
    {
        foreach ($fields as $field => $value) {
            if (! in_array($field, $this->fields))
                throw new \Exception('未定义的字段' . $field);
            $this->getSelected();
            foreach ($this->selectedPk as $pk) {
                if (isset($this->indexs[$field])) {
                    $oldValue = $this->redis->hget($pk, $field);
                }
                $this->redis->hset($pk, $field, $value);
                
                $this->updateIndex($field, $value, $oldValue, $pk);
            }
        }
        return count($this->selectedPk);
    }
    
    /*
     * 递增、递减数值字段
     * @param String $field 操作的字段
     * @param Int $num 值 可以是负数
     */
    public function incField ($field, $num)
    {
        if (! in_array($field, $this->fields))
            throw new \Exception('未定义的字段' . $field);
        $this->getSelected();
        foreach ($this->selectedPk as $pk) {
            
            $value = $this->redis->hIncrBy($pk, $field, $num);
            
            if (isset($this->indexs[$field])) {
                $oldValue = $res - $num;
            }
            
            $this->updateIndex($field, $value, $oldValue, $pk);
        }
        return count($this->selectedPk);
    }
    
    /*
     * 更新索引
     * @param String $field 索引所在字段名
     * @param String $value 新的值
     * @param String $oldValue 旧的值
     * @param String $pk 主记录hash键名
     */
    protected function updateIndex ($field, $value, $oldValue, $pk)
    {
        /* 移除与该字段和该值相关的索引 */
        $indexType = $this->indexs[$field];
        /* 如果是非索引字段 */
        if (empty($indexType))
            return;
            
            // num类型索引只需修改原集合
        if ($indexType == 'num') {
            $this->updateIndex_num($field, $value, $pk);
            return;
        }
        
        $delIndexMethod = 'delIndex_' . $indexType;
        $this->$delIndexMethod($field, $oldValue, $pk);
        
        /* 根据字段和对应的新值建立或者更新索引 */
        $createMethod = 'create_' . $indexType;
        $this->$createMethod($field, $value, $pk);
    }

    /**
     * 设置条件，当字段$field的值等于$value时
     *
     * @param String $field
     *            字段名
     * @param String $values
     *            可以是多个值,好隔开
     *            字段值
     * @param String $logic
     *            逻辑标识 'and'、'or'
     * @return $this
     *
     */
    public function byField ($field, $values, $logic = 'and')
    {
        /* 如果通过主键查询则进行初始化 */
        if ($field == $this->pkField)
            $this->initData($values);
        
        $indexType = $this->indexs[$field];
        if (empty($indexType) || $indexType != 'unk' && $indexType != 'pk')
            throw new \Exception('byField条件字段未设置unk索引');
            /* 根据字段和值条件生成中间集合 */
        $values = explode(',', $values);
        foreach ($values as $k => $value) {
            $midkey1 = $this->makeUnk($field, $value);
            $midkey2 = $this->tempZsetKey_mid;
            $this->redis->zunionstore($this->tempZsetKey_mid, 
                    [
                            $midkey1,
                            $midkey2
                    ]);
        }
        $this->initTempZset(); // 在初始化mid后，才能初始化end临时集合
        /* 根据logic条件选择使用交集还是并集合成end集合 */
        $endkey1 = $this->tempZsetKey_mid;
        $endkey2 = $this->tempZsetKey_end;
        if ($logic == 'and') {
            $this->redis->zinterstore($this->tempZsetKey_end, 
                    [
                            $endkey1,
                            $endkey2
                    ]);
        } else {
            $this->redis->zunionstore($this->tempZsetKey_end, 
                    [
                            $endkey1,
                            $endkey2
                    ]);
        }
        $this->redis->del($this->tempZsetKey_mid);
        return $this;
    }

    /**
     * 设置条件，当字段$field的值在$min-$max区间时
     * $min、$max = '(number'表示不包括 $min、$max
     * $min='-inf' 表示 $min不设下限
     * $max='+inf' 表示 $max不设上限
     *
     * @param String $field
     *            字段名
     * @param Int $min
     *            区间下限
     * @param Int $max
     *            区间上限
     * @param String $logic
     *            逻辑标识 'and' 、'or'
     * @return $this
     *
     */
    public function byScope ($field, $min, $max, $logic = 'and')
    {
        $indexType = $this->indexs[$field];
        if (empty($indexType) || $indexType != 'num')
            throw new \Exception('byScope条件字段未设置num索引');
            
            /* 处理包括$min、$max本身问题 */
        $min = (strpos($min, '(') === false) ? ('(' . $min) : trim($min, '(');
        $max = (strpos($max, '(') === false) ? ('(' . $max) : trim($max, '(');
        
        /* 根据条件生成中间集合 */
        $scopekey = $this->makeNum($field);
        /* 复制一份到中间集合 */
        $this->redis->zunionstore($this->tempZsetKey_mid, 
                [
                        $scopekey,
                        $scopekey
                ], 
                [
                        0,
                        1
                ]);
        /* 范围筛选,移除$min~$max以外的元素 */
        $this->redis->zRemRangeByScore($this->tempZsetKey_mid, '-inf', $min);
        $this->redis->zRemRangeByScore($this->tempZsetKey_mid, $max, '+inf');
        $endkey1 = $this->tempZsetKey_mid;
        $endkey2 = $this->tempZsetKey_end;
        /* 操作临时end集合 */
        $this->initTempZset(); // 在初始化mid后，才能初始化end临时集合
        if ($logic == 'and') {
            $res = $this->redis->zinterstore($this->tempZsetKey_end, 
                    [
                            $endkey1,
                            $endkey2
                    ], 
                    [
                            1,
                            0
                    ]);
        } else {
            $this->redis->zunionstore($this->tempZsetKey_end, 
                    [
                            $endkey1,
                            $endkey2
                    ], 
                    [
                            1,
                            0
                    ]);
        }
        $this->redis->del($this->tempZsetKey_mid);
        return $this;
    }
    
    /*
     * 设置根据某个字段排序
     * @param String $fields 参与排序的字段列表
     * @return $this
     */
    public function order ($fields, $sort = 'asc')
    {
        $key1 = $this->tempZsetKey_end;
        $fields = explode(',', $fields);
        foreach ($fields as $field) {
            $indexType = $this->indexs[$field];
            if (empty($indexType) || $indexType != 'num')
                throw new \Exception('order条件字段未设置num索引');
            $key2 = $this->makeNum($field);
            $this->redis->zinterstore($this->tempZsetKey_end, 
                    [
                            $key1,
                            $key2
                    ], 
                    [
                            0,
                            1
                    ]);
        }
        /* 标识为已经根据条件创建过一次临时集合了 */
        $this->isCreated = true;
        $this->sort = $sort;
        return $this;
    }
    
    /*
     * 根据分页参数设置区间$start,$stop
     * @param Int $firstRow 偏移量
     * @param Int $listRows 列表数目
     * @return $this
     */
    public function limit ($firstRow, $listRows)
    {
        $this->start = $firstRow;
        $this->stop = $firstRow + $listRows - 1;
        return $this;
    }

    public function select ()
    {
        $source = [];
        
        $this->getSelected();
        
        foreach ($this->selectedPk as $pk) {
            $source[] = $this->redis->hgetall($pk);
        }
        
        return $source;
    }
    
    /*
     * 获取列表总数
     * @return Int $count 列表总数
     */
    public function count ()
    {
        $key = $this->tempZsetKey_end;
        $count = $this->redis->zcount($key, '-inf', '+inf');
        $this->redis->del($this->tempZsetKey_end);
        $this->isCreated = false;
        return $count;
    }
    
    /*
     * 获取设置选择到的主键列表
     */
    protected function getSelected ()
    {
        $key = $this->tempZsetKey_end;
        if (! $this->isCreated)
            $key = $this->pkIndexKey;
        $rangeMethod = ($this->sort == 'desc') ? 'ZREVRANGE' : 'ZRANGE';
        $res = $this->redis->$rangeMethod($key, $this->start, $this->stop);
        $this->selectedPk = $res;
        $this->redis->del($this->tempZsetKey_end);
        $this->isCreated = false;
    }
    
    /*
     * 插入到主记录
     * @param Array $data 一条记录
     * @return String $key 这条记录的hash Key名
     */
    protected function insertRow ($data)
    {
        $pkField = array_search('pk', $this->indexs);
        $pk = $this->makePk($data[$this->pkField]);
        
        $this->redis->hmset($pk, $data);
        
        return $pk;
    }
    
    /*
     * 创建索引
     * @param Array $data一条记录
     * @param String $pk 主记录的hashKey名
     */
    protected function createIndex ($data, $pk)
    {
        foreach ($this->indexs as $field => $indexType) {
            $value = $data[$field];
            $createMethod = 'create_' . $indexType;
            $this->$createMethod($field, $value, $pk);
        }
    }
    
    /*
     * 创建一条唯一索引记录到redis
     * @param String $field 字段名
     * @param String 字段值 $value
     * @param String $pk 主记录的hashKey名
     */
    protected function create_unk ($field, $value, $pk)
    {
        $key = $this->makeUnk($field, $value);
        $this->redis->zadd($key, 0, $pk);
    }
    
    /*
     * 创建一条数值型索引记录到redis
     * @param String $field 字段名
     * @param String 字段值 $value
     * @param String 主记录hashKey名
     */
    protected function create_num ($field, $value, $pk)
    {
        $key = $this->makeNum($field);
        if (! is_numeric($value))
            throw new \Exception('字段索引类型为num遇上非数字值:' . $value);
        $this->redis->zadd($key, $value, $pk);
    }
    
    /*
     * 创建主键索引到redis
     * @param String $pk 主记录hash键名
     */
    protected function create_pk ($field = null, $value = null, $pk)
    {
        $this->redis->zadd($this->pkIndexKey, 0, $pk);
        $key = $this->makeUnk($field, $value);
        $this->redis->zadd($key, $value, $pk);
    }
    
    /*
     * 在删除记录后删除对应的索引
     * @param Array $data 这条记录对应的数据集
     * @param String 主键hashKey名
     */
    protected function delIndex ($data, $pk)
    {
        foreach ($this->indexs as $index => $type) {
            $value = $data[$index];
            $delIndexMethod = 'delIndex_' . $type;
            $this->$delIndexMethod($index, $value, $pk);
        }
    }

    /**
     * 删除唯一索引
     *
     * @param String $field
     *            对应的字段名
     * @param String $value
     *            字段对应的值
     * @param String $pk
     *            主键hashKey名
     */
    protected function delIndex_unk ($field, $value, $pk)
    {
        $key = $this->makeUnk($field, $value);
        
        $this->redis->zrem($key, $pk);
    }

    /**
     * 删除数值索引
     *
     * @param String $field
     *            对应的字段名
     * @param String $value
     *            字段对应的值
     * @param String $pk
     *            主键hashKey名
     */
    protected function delIndex_num ($field, $value, $pk)
    {
        $key = $this->makeNum($field);
        
        $this->redis->zrem($key, $pk);
    }
    
    /*
     * 更新数值类型索引，直接覆盖主键对应的分数即可
     * @param String $field 索引对应的字段
     * @param Int $value 新值
     * @param String $pk 主哈希表键名
     */
    protected function updateIndex_num ($field, $value, $pk)
    {
        $key = $this->makeNum($field);
        $this->redis->zadd($key, $value, $pk);
    }
    
    /*
     * 删除主键索引
     */
    protected function delIndex_pk ($field, $value, $pk)
    {
        $this->redis->zrem($this->pkIndexKey, $pk);
        $key = $this->makeUnk($field, $value);
        $this->redis->del($key);
    }

    /**
     * 基础数据检查,字段合法性
     *
     * @param Array $data
     *            一条数据
     *            throw \Exception
     *            
     */
    protected function checkField ($data)
    {
        $dataFields = array_keys($data);
        $dataValues = array_values($data);
        if ($this->fields != $dataFields)
            throw new \Exception('数据字段错误' . json_encode($dataFields));
    }
    
    /*
     * 制作一个记录对应的hashkey名
     * @param Int $id 主键id
     * @return String
     */
    protected function makePk ($id)
    {
        return str_replace(
                [
                        '{table}',
                        '{id}'
                ], 
                [
                        $this->table,
                        $id
                ], $this->tableTempla);
    }

    /**
     * 制作一个唯一索引对应的zsetKey名
     *
     * @param String $field
     *            索引对应的字段名
     * @param String $value
     *            字段对应的值
     *            
     */
    protected function makeUnk ($field, $value)
    {
        return str_replace(
                [
                        '{table}',
                        '{field}',
                        '{val}'
                ], 
                [
                        $this->table,
                        $field,
                        $value
                ], $this->unkTempla);
    }

    /**
     * 制作一个数值索引对应的zsetKey名
     *
     * @param
     *            String 索引对应的字段名
     *            
     */
    protected function makeNum ($field)
    {
        return str_replace(
                [
                        '{table}',
                        '{field}'
                ], 
                [
                        $this->table,
                        $field
                ], $this->numTempla);
    }
    
    /*
     * 移除临时集合
     */
    public function __destruct ()
    {
        $this->redis->del($this->tempZsetKey_end);
        $this->redis->del($this->tempZsetKey_mid);
    }
    
    /*
     * 从mysql初始化依赖数据
     * @param String $values 查询的主键值列表
     */
    protected function initData ($values)
    {
        $values = explode(',', $values);
        /* 计算出需要从mysql获取的id列表 */
        $needIds = [];
        foreach ($values as $v) {
            $pk = $this->makePk($v);
            if ($res = $this->redis->zScore($this->pkIndexKey, $pk) === false)
                $needIds[] = $v;
        }
        $map = array(
                $this->pkField => array(
                        'in',
                        join(',', $needIds)
                )
        );
        $res = M($this->table)->field($this->fields)
            ->where($map)
            ->select();
        foreach ($res as $v) {
            $this->insert($v);
        }
    }
    
    /*
     * 初始化临时集合,如果是第一次设置条件则end集合的元素为mid集合
     * 设置mid、end集合的过期时间避免碎片数据
     */
    protected function initTempZset ()
    {
        if (! $this->isCreated)
            $this->redis->zunionstore($this->tempZsetKey_end, 
                    [
                            $this->tempZsetKey_end,
                            $this->tempZsetKey_mid
                    ]);
        $this->redis->expireat($this->tempZsetKey_end, time() + 120);
        $this->redis->expireat($this->tempZsetKey_mid, time() + 120);
        /* 标识为已经根据条件创建过一次临时集合了 */
        $this->isCreated = true;
    }
    
    /*
     * 获取某主键id对应的行的某个字段值在符合条件结果集里面的排名
     * @param String $field 字段名
     * @param $id 值
     * @return Int 名次值
     */
    public function getRankValue ($field, $id)
    {
        $pk = $this->makePk($id);
        $this->order($field, 'desc');
        
        return $this->redis->zrevrank($this->tempZsetKey_end, $pk) + 1;
    }

    public function getFields ()
    {
        return $this->fields;
    }
    
    /*
     * 获取所有记录
     * @return $this;
     */
    public function getAll ()
    {
        $this->redis->zunionstore($this->tempZsetKey_end, 
                [
                        $this->pkIndexKey,
                        $this->tempZsetKey_end
                ]);
        /* 标识为已经根据条件创建过一次临时集合了 */
        $this->isCreated = true;
        return $this;
    }
}
