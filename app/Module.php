<?php
/**
 * AtroPIM S3 Storage Addon
 *
 * This source file is available under GNU General Public License version 3 (GPLv3).
 * Full copyright and license information is available in LICENSE.txt, located in the root directory.
 *
 * @copyright  Copyright (c) Sthala Technologies
 * @license    GPLv3 (https://www.gnu.org/licenses/)
 */

declare(strict_types=1);

namespace S3Storage;

use Atro\Core\ModuleManager\AbstractModule;
use S3Storage\Core\FileStorage\S3FileStorage;

class Module extends AbstractModule
{
    public static function getLoadOrder(): int
    {
        return 9500;
    }

    public function onLoad()
    {
        // Registers the "s3" Storage type: Atro\Repositories\Storage::getFileStorage()
        // resolves the backend by looking up "{$storage->get('type')}Storage" in the
        // container, so a Storage record with type=s3 resolves to the alias below.
        $this->getContainer()->setClassAlias('s3Storage', S3FileStorage::class);
    }
}
