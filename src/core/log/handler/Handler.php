<?php

namespace framework\core\log\handler;

interface Handler {

    public function write(array $messages);
}
