<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Platform;

use Doctrine\DBAL\Platforms\Keywords\KeywordList;

/** @phpstan-ignore class.extendsDeprecatedClass */
class ODataKeywords extends KeywordList
{
    protected function getKeywords(): array
    {
        return [];
    }
}
