<?php

it('reports API v1 health', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'version' => 'v1']);
});
