<?php

namespace Theintz\PhpDaemon\Worker;

use Theintz\PhpDaemon\Exception;

class Call
{

    public $return;
    public $args;

    public $method;
    public $status;
    public $pid;
    public $id;
    public $size          = 64;
    public $retries       = 0;
    public $errors        = 0;
    public $gc            = false;
    public $time          = array();


    public function __construct($id, $method = null, array $args = null) {
        $this->id          = $id;
        $this->method      = $method;
        $this->args        = $args;
        $this->update_size();
        $this->uncalled();
    }

    /**
     * Determine the time this call spent (or has spent thus far) in RUNNING state
     * @return int|mixed
     */
    public function runtime() {
        switch($this->status) {
            case Mediator::RUNNING:
                return microtime(true) - $this->time[Mediator::RUNNING];

            case Mediator::RETURNED:
                return $this->time[Mediator::RETURNED] - $this->time[Mediator::RUNNING];

            default:
                return 0;
        }
    }

    /**
     * Merge in data from the supplied $call into this call
     * @param Call $call
     * @return Call
     */
    public function merge(Call $call) {
        // This could end up being more sophisticated and complex.
        // But for now, the only modifications to this struct in the worker are timestamps at status changes.
        $this->time[Mediator::CALLED] = $call->time[Mediator::CALLED];
        return $this;
    }

    /**
     * Active calls
     * @return bool
     */
    public function is_active() {
        return !in_array($this->status, array(Mediator::TIMEOUT, Mediator::RETURNED, Mediator::CANCELLED));
    }

    /**
     * Reduce the memory footprint of this call by unsetting argument & return details that could be memory intensive.
     * All other meta-data of the call will be preserved for analytical purposes. Sets a $gc flag upon completion.
     * @return bool
     */
    public function gc() {
        if ($this->gc || $this->is_active())
            return false;

        unset($this->args, $this->return);
        $this->gc = true;
        return true;
    }

    /**
     * Return a message header with pertinent details. Using the SysV via, for example, this is sent on the mq,
     * and the message body is set on shm.
     * @return array
     */
    public function header() {
        return array (
            'call_id'   => $this->id,
            'status'    => $this->status,
            'microtime' => $this->time[$this->status],
            'pid'       => getmypid(),
            // I really have no idea why we are sending the pid along in the message header like this, but I assume
            // there's a good reason so for now I'll just continue to include it.
        );
    }

    /**
     * Prepare the Call struct to be passed back to the Mediator::call() method for another go-around
     * @return void
     */
    public function retry() {
        $this->retries++;
        $this->errors = 0;
        $this->uncalled();
    }

    public function timeout($microtime = null) {
        return $this->status(Mediator::TIMEOUT,    $microtime);
    }

    public function cancelled($microtime = null) {
        return $this->status(Mediator::CANCELLED,  $microtime);
    }

    public function returned($return, $microtime = null) {
        $this->return = $return;
        $this->update_size();
        return $this->status(Mediator::RETURNED,   $microtime);
    }

    public function running($microtime = null) {
        $this->pid = getmypid();
        return $this->status(Mediator::RUNNING,    $microtime);
    }

    public function called($microtime = null) {
        return $this->status(Mediator::CALLED,     $microtime);
    }

    public function uncalled($microtime = null) {
        return $this->status(Mediator::UNCALLED,   $microtime);
    }

    /**
     * Get the appropriate queue based on the current status
     * @return int
     */
    public function queue() {
        return Mediator::$queue_map[$this->status];
    }

    public function status($status, $microtime = null) {
        // You can restart a Call (back to status 0) but you can't decrement or arbitrarily set the status
        if ($status < $this->status && $status > 0)
            throw new Exception(__METHOD__ . " Failed: Cannot Rewind Status. Current Status: {$this->status} Given: {$status}");

        if ($microtime === null)
            $microtime = microtime(true);

        $this->status = $status;
        $this->time[$status] = $microtime;

        return $this;
    }

    private function update_size() {
        $this->size = strlen(print_r($this, true));
    }

}