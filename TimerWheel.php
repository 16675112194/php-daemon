<?php

class TimerWheel
{
    public $timerManager;
    public $arrTask;
    
    public $tvrBits = 8;
    public $tvnBits = 6;
    
    public $tvrSize;
    public $tvnSize;
    
    public $tvrMask;
    public $tvnMask;
    
    public $maxTval = (1 << 32) - 1;
    
    public $fc = ['s', 'i', 'H', 'd', 'm', 'w'];
    public $ary = [60, 60, 24, 0, 12, 7];
    
    public $current = 0; //当前时间
    
    public $lastTime;
    
    public $logFile;
    
    public function __construct()
    {
        $this->tvrSize = 1 << $this->tvrBits;
        $this->tvnSize = 1 << $this->tvnBits;
        
        $this->tvrMask = $this->tvrSize - 1;
        $this->tvnMask = $this->tvnSize - 1;
        
        $this->timerManager = range(0, 4);
        for ($i = 0; $i < 5; $i++) {
            $this->timerManager[$i] = [];
            if ($i == 0) {
                $size = $this->tvrSize;
            } else {
                $size = $this->tvnSize;
            }
            for ($j = 0; $j < $size; $j++) {
                $this->timerManager[$i][$j] = [];
            }
        }
        
        $this->ary[3] = date('t');
        
        $this->lastTime = time();
    }
    
    /**
     * expires
     * format a recently exec time, then make a expires time
     * receive a frequency like crontab * * * * * *
     * @param string $frequency
     * @return number
     */
    public function expires(string $frequency)
    {
        $fr = explode(" ", $frequency);
        $fields = []; //列举多维度的有效时间数组
        for ($i = 0; $i < count($fr); $i++) {
            if ($fr[$i] == '*') {
                $fields[$i] = range(0, $this->ary[$i] - 1);
            } elseif (preg_match("/\*\/(\d*)/", $fr[$i], $matches)) {
                $fields[$i] = ['f' => $matches[1]];
            } elseif (preg_match("/(\d*)-(\d*)/", $fr[$i], $matches)) {
                $fields[$i] = range($matches[1], $matches[2]);
            } else {
                $fields[$i] = explode(",", $fr[$i]);
            }
        }
        //print_r($fields);
        /* 查找一个最近的有效时间 */
        $incr = 0;
        $year = date("Y");
        $tk = [0,0,0,0,0,0];//时间指针数组，代表查找到的每一个纬度最近时间
        for ($i = 0; $i < count($fr); $i++) {
            $d = date($this->fc[$i]);
            if (!isset($fields[$i]['f'])) {
                // 检查合适的时间位置
                for ($j = 0; $j < count($fields[$i]); $j++) {
                    if (($d <= $fields[$i][$j] && $j == 0) || ($d <= $fields[$i][$j] && $d > $fields[$i][$j-1])) {
                        if ($incr) {
                            if ($fields[$i][$j] == $d) {
                                $j++;
                            }
                            //有进位则把前面的指针都置为零
                            foreach ($tk as $k => &$v) {
                                if (isset($fields[$k]['f'])) {
                                    $fields[$k][0] = (date($this->fc[$k]) + $fields[$k]['f']) % $this->ary[$k];
                                } else {
                                    $v = 0;
                                }
                            }
                        }
                        break;
                    }
                }
                //echo $i,' ',$incr,' '.$j." ";
                if (!isset($fields[$i][$j])) {
                    $j = 0;
                    if ($i == 4) {
                        $year++;
                        $incr = 0; //消除进位
                        //有进位则把前面的指针都置为零
                        foreach ($tk as $k => &$v) {
                            if (isset($fields[$k]['f'])) {
                                $fields[$k][0] = (date($this->fc[$k]) + $fields[$k]['f']) % $this->ary[$k];
                            } else {
                                $v = 0;
                            }
                        }
                    } else {
                        $incr = 1; //产生进位
                    }
                } else {
                    $incr = 0; //消除进位
                }
                $tk[$i] = $j;
            } else {
                if ($incr) {
                    $incr = 0;
                    //有进位则把前面的指针都置为零
                    foreach ($tk as $k => &$v) {
                        if (isset($fields[$k]['f'])) {
                            $fields[$k][0] = (date($this->fc[$k]) + $fields[$k]['f']) % $this->ary[$k];
                        } else {
                            $v = 0;
                        }
                    }
                    $fields[$i][0] = $d + $fields[$i]['f'] + 1;
                } else {
                    $fields[$i][0] = $d + $fields[$i]['f'];
                }
                
                if ($fields[$i][0] >= $this->ary[$i]) {
                    $incr = 1;
                }
                $fields[$i][0] %= $this->ary[$i];
            }
        }
        //按照指针数组合成最近执行时间
        $datetime = $year."-".$fields[4][$tk[4]]."-".$fields[3][$tk[3]]." ".$fields[2][$tk[2]].":".$fields[1][$tk[1]].":".$fields[0][$tk[0]];
        
        $this->printLog(__FUNCTION__." ".date("Y-m-d H:i:s")." ".$frequency." => ".$datetime);
        return (int)((strtotime($datetime) - (time() - $this->current))); //定时器到期时间
    }
    
    /**
     * create a task
     * @param unknown $frequency
     * @param unknown $id
     * @return unknown[]|number[]
     */
    public function createTask($frequency, $id)
    {
        if (isset($this->arrTask[$id])) {
            $this->arrTask[$id]['fre'] = $frequency;
            $this->arrTask[$id]['expires'] = $this->expires($frequency);
            $task = $this->arrTask[$id];
        } else {
            $task = ['fre' => $frequency, 'id' => $id, 'expires' => $this->expires($frequency)];
            $this->arrTask[$id] = $task;
        }
        $this->printLog(__FUNCTION__." ".json_encode($this->arrTask[$id]));
        
        $this->addTask($this->arrTask[$id]);
        
        return $task;
    }
    
    /**
     * add task
     * @param unknown $task
     */
    public function addTask($task)
    {
        $id = $task['id'];
        
        $expires = $task['expires']; //定时器到期时间
        $uDueTime = $expires - $this->current; //触发剩余时间
        //$this->printLog(__FUNCTION__." ".$uDueTime." ".json_encode($this->arrTask));
        
        if ($uDueTime < $this->tvrSize) {
            $i = $expires & $this->tvrMask;
            $this->timerManager[0][$i][$id] = &$this->arrTask[$id];
            $this->arrTask[$id][1] = 0; //轮的层级
        } elseif ($uDueTime < 1 << ($this->tvrBits + $this->tvnBits)) {
            $i = ($expires >> $this->tvrBits) & $this->tvnMask;
            $this->timerManager[1][$i][$id] = &$this->arrTask[$id];
            $this->arrTask[$id][1] = 1;
        } elseif ($uDueTime < 1 << ($this->tvrBits + $this->tvnBits * 2)) {
            $i = ($expires >> ($this->tvrBits + $this->tvnBits)) & $this->tvnMask;
            $this->timerManager[2][$i][$id] = &$this->arrTask[$id];
            $this->arrTask[$id][1] = 2;
        } elseif ($uDueTime < 1 << ($this->tvrBits + $this->tvnBits * 3)) {
            $i = ($expires >> ($this->tvrBits + $this->tvnBits * 2)) & $this->tvnMask;
            $this->timerManager[3][$i][$id] = &$this->arrTask[$id];
            $this->arrTask[$id][1] = 3;
        } elseif ($uDueTime < 1 << ($this->tvrBits + $this->tvnBits * 4)) {
            $i = ($expires >> ($this->tvrBits + $this->tvnBits * 3)) & $this->tvnMask;
            $this->timerManager[4][$i][$id] = &$this->arrTask[$id];
            $this->arrTask[$id][1] = 4;
        }
        $this->arrTask[$id][2] = $i; //轮中的槽
        $this->printLog(__FUNCTION__." ".json_encode($this->arrTask[$id])." DueTime:".$uDueTime);
        //echo __FUNCTION__." ".json_encode($this->arrTask[$id])."\n";
    }
    
    public function delTask($id)
    {
        if (isset($this->arrTask[$id])) {
            unset($this->timerManager[$this->arrTask[$id][1]][$this->arrTask[$id][2]][$id]);
            unset($this->arrTask[$id]);
        }
        
        return 1;
    }
    
    public function updateTask($id)
    {
        if (isset($this->arrTask[$id])) {
            unset($this->timerManager[$this->arrTask[$id][1]][$this->arrTask[$id][2]][$id]);
            $this->createTask($this->arrTask[$id]['fre'], $this->arrTask[$id]['id']);
        }
        
        return 1;
    }
    
    /**
     * cascade update task
     * @param unknown $lv
     */
    public function cascadeTask($lv)
    {
        if ($lv == 1) {
            $i = $this->current >> $this->tvrBits;
        } else {
            $i = $this->current >> ($this->tvrBits + $this->tvnBits * ($lv) - 1);
        }
        
        if ($i > 0 && 0 == $i & $this->tvnMask) { //判断时间进位
            $this->cascadeTask($lv+1);
        }
        
        $i %=  $this->tvnSize;
        $this->printLog("lv=".$lv." i=".$i);
        foreach ($this->timerManager[$lv][$i] as $id => $task) {
            unset($this->timerManager[$lv][$i][$id]);
            $this->addTask($task);
        }
    }
    
    public function getTask()
    {
        $taskList = [];
        $now = time();
        //$now = $this->lastTime+1;
        if ($this->lastTime != $now) {
            //$this->printLog("++++++++++++++++++++++++++++begin getTask++++++++++++++++++++++++++++");
            while ($this->lastTime != $now) { //避免程序执行产生的时间误差导致跳过>1秒
                if ($this->current != 0 && ($this->current & $this->tvrMask) == 0) {
                    $this->printLog("jiff=".$this->current);
                    $this->cascadeTask(1);
                }
                
                $taskList = array_merge($taskList, $this->timerManager[0][$this->current & $this->tvrMask]);
                $this->current++; //当前时间++
                $this->lastTime++;
                foreach ($taskList as $task) {
                    $this->printLog(__FUNCTION__." ".json_encode($task)." ".($this->current-1));
                    $this->updateTask($task['id']);
                }
            }
            //$this->printLog("+++++++++++++++++++++++++++++end getTask+++++++++++++++++++++++++++++");
        }
        return $taskList;
    }
    
    public function setLogFile($logFile)
    {
        $this->logFile = $logFile;
    }
    
    public function printLog($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg);
        }
        $msg = date("Y-m-d H:i:s")." ".posix_getpid()." ".$msg." current:".$this->current."\n";
        if ($this->logFile) {
            file_put_contents($this->logFile, $msg, FILE_APPEND | LOCK_EX);
        } else {
            echo $msg;
        }
    }
}
