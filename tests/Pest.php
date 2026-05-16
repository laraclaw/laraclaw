<?php

uses(Laraclaw\Tests\TestCase::class)->in('Feature');
uses(Laraclaw\Tests\E2E\E2ETestCase::class)->in('E2E');
pest()->group('e2e')->in('E2E');
