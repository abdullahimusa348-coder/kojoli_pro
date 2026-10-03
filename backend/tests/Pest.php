<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Bind Laravel's TestCase to Feature tests so $this->get(), $this->getJson() etc. work.
// Each Feature test runs against a fresh in-memory database.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
