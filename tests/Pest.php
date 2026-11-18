<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot Laravel with a fresh in-memory database.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

// Engine tests are PURE PHP (BLUEPRINT rule): no TestCase, no container, no database.
pest()->in('Engine');
