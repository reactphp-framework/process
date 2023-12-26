<?php

namespace Reactphp\Framework\Process;

class ProcessManager
{
    use \Reactphp\Framework\Single\Single;

    protected $process;
    public static $php;

    public function initProcessNumber($number = 0)
    {
        if ($this->process) {
            return $this->process;
        }
        return $this->process = new Process($number, static::$php);
    }

    public function __set($name, $value)
    {
        if (!$this->process) {
            $this->initProcessNumber();
        }

        $this->process->{$name} = $value;
    }

    public function __call($name, $arguments)
    {
        if (!$this->process) {
            $this->initProcessNumber();
        }

        return call_user_func_array([$this->process, $name], $arguments);
    }
}