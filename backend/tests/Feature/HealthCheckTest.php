<?php

it('responds on the framework health route', function () {
    $this->get('/up')->assertOk();
});
