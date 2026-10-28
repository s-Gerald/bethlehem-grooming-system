<?php

return json_decode(file_get_contents(__DIR__.'/../resources/data/philippine-mobile-policy.json'), true, flags: JSON_THROW_ON_ERROR);
