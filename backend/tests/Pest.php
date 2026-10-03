<?php

// Bind Laravel's TestCase to Feature tests so $this->get(), $this->getJson() etc. work.
pest()->extend(Tests\TestCase::class)->in('Feature');
