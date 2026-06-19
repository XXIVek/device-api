<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddActivationCodeToLicenses extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('licenses');
        
        // Добавляем колонку для кода активации
        $table->addColumn('activation_code', 'string', [
            'limit' => 10,
            'null' => true,
            'comment' => 'Временный код для сопряжения устройства'
        ])
        // Добавляем время истечения кода
        ->addColumn('code_expires_at', 'datetime', [
            'null' => true,
            'comment' => 'Время истечения действия кода активации'
        ])
        // Индекс для быстрого поиска по коду
        ->addIndex(['activation_code'], ['unique' => true, 'name' => 'idx_activation_code'])
        ->update();
    }
}
