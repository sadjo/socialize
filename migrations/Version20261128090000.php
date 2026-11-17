<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photo de profil et couverture recadrables : image source gardée et cadre choisi. Écrite avec l'API Schema pour
 * rester portable entre SQLite et MySQL / MariaDB.
 */
final class Version20261128090000 extends AbstractMigration
{
    private const array COLUMNS = [
        'avatar_source_path' => 255,
        'avatar_crop' => 100,
        'cover_source_path' => 255,
        'cover_crop' => 100,
    ];

    public function getDescription(): string
    {
        return 'Recadrage de la photo de profil et de la couverture';
    }

    public function up(Schema $schema): void
    {
        $accounts = $schema->getTable('accounts');
        foreach (self::COLUMNS as $name => $length) {
            $accounts->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $accounts = $schema->getTable('accounts');
        foreach (array_keys(self::COLUMNS) as $name) {
            $accounts->dropColumn($name);
        }
    }
}
