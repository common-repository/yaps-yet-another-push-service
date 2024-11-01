<?php

interface iConnector {
    public function init();
    public function addDevice($str);
    public function sendMessage($message);
}