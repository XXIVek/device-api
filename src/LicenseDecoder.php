<?php

namespace App;

class LicenseDecoder
{
    /**
     * Декодирует строку лицензии (112 символов в UTF-8).
     *
     * @param string $encodedString Полная строка: 12 символов ключа + 100 зашифрованных
     * @return array
     * @throws \InvalidArgumentException
     */
    public function decode(string $encodedString): array
    {
        mb_internal_encoding('UTF-8');

        //$count_str=mb_strlen($encodedString, 'UTF-8');
        if (mb_strlen($encodedString, 'UTF-8') !== 112) {
            throw new \InvalidArgumentException('Encoded string must be exactly 112 characters long');
        }

        $keyPart = mb_substr($encodedString, 0, 12, 'UTF-8');
        $dataPart = mb_substr($encodedString, 12, 100, 'UTF-8');

        $codeFromKey = mb_substr($keyPart, 0, 7, 'UTF-8');
        $controlStr = mb_substr($keyPart, 4, 8, 'UTF-8');
        $controlNumber = is_numeric($controlStr) ? (int)$controlStr : 0;

        $genNumber = $this->getGenNumber($controlNumber, 6, 80);

        $table = $this->buildPermutationTable($dataPart, $genNumber);

        usort($table, fn($a, $b) => $a['number'] <=> $b['number']);

        $plain = implode('', array_column($table, 'symbol'));

        $mainContent = mb_substr($plain, 0, 82, 'UTF-8');
        $licenseNumberFromPlain = mb_substr($plain, -5, 5, 'UTF-8');

        // Демо-версии не обрабатываем
        if ($licenseNumberFromPlain === '00000') {
            return [
                'success'                => false,
                'errorCode'              => -99,
                'errorMessage'           => 'Demo license cannot be processed',
                'codeFromKey'            => $codeFromKey,
                'licenseNumberFromKey'   => mb_substr($keyPart, 7, 5, 'UTF-8'),
                'plainData'              => $plain,
                'mainContent'            => $mainContent,
                'licenseNumberFromPlain' => $licenseNumberFromPlain,
                'demo'                   => true,
            ];
        }

        $fields = explode(',', $mainContent);
        $fields = array_map('trim', $fields);
        $fieldCount = count($fields);

        // Определение версии по количеству полей
        $version = 2;
        $deliveryNumber = null;

        // Если полей 5 и более, считаем пятое полем deliveryNumber (версия 3)
        if ($fieldCount >= 5) {
            $version = 3;
            $deliveryNumber = $fields[4] ?? 0;
        }

        // Основные поля всегда находятся в первых четырёх элементах (индексы 0-3)
        $innField = $fields[0] ?? '';
        $kppField = $fields[1] ?? '';
        $orgField = $fields[2] ?? '';
        $cityField = $fields[3] ?? '';

        // Обработка ИНН
        if (str_starts_with($innField, 'ИНН')) {
            $inn = trim(mb_substr($innField, 3, null, 'UTF-8'));
        } else {
            $inn = trim($innField);
        }

        // Обработка КПП
        if (str_starts_with($kppField, 'КПП')) {
            $kpp = trim(mb_substr($kppField, 3, null, 'UTF-8'));
        } else {
            $kpp = trim($kppField);
        }

        $organization = trim($orgField);
        $city = trim($cityField);

        $result = [
            'success'                => true,
            'codeFromKey'            => $codeFromKey,
            'licenseNumberFromKey'   => mb_substr($keyPart, 7, 5, 'UTF-8'),
            'plainData'              => $plain,
            'mainContent'            => $mainContent,
            'licenseNumberFromPlain' => $licenseNumberFromPlain,
            'fields'                 => $fields,
            'inn'                    => $inn ?: null,
            'kpp'                    => $kpp ?: null,
            'organization'           => $organization ?: null,
            'city'                   => $city ?: null,
            'demo'                   => false,
            'version'                => $version,
            'deliveryNumber'         => $deliveryNumber,
            'errorCode'              => null,
        ];

        //if ($version === 3) {
        //    $result['deliveryNumber'] = $deliveryNumber;
        //}

        return $result;
    }

    // Остальные методы (getGenNumber, buildPermutationTable) без изменений
    private function getGenNumber(int $tekKontrChislo = 0, int $znMin = 0, int $znMax = 0): int
    {
        $tekChislo = $tekKontrChislo ?: time();
        $osnovanie = $znMax > 0 ? $znMax : 1000000000;

        while (true) {
            if ($tekChislo < 69) {
                $tekChislo /= 68;
            } else {
                $tekChislo /= 200;
            }
            if ($tekChislo <= 1) {
                $mnozhitelPI = $tekChislo;
                break;
            }
        }

        $genChislo = (int)round($osnovanie * $mnozhitelPI, 0);

        if ($znMin > 0 && $genChislo < $znMin) {
            $genChislo += $znMin - 1;
        }

        return $genChislo;
    }

    private function buildPermutationTable(string $dataPart, int $startSeed): array
    {
        $table = [];
        $koef = 0;
        $kolStrTab = 0;
        $kodChislo1 = $startSeed;
        $length = mb_strlen($dataPart, 'UTF-8');

        while ($kolStrTab < $length) {
            $koef++;

            $found = false;
            for ($i = 0; $i < $kolStrTab; $i++) {
                if ($table[$i]['number'] == $kodChislo1) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $symbol = mb_substr($dataPart, $kolStrTab, 1, 'UTF-8');
                $table[$kolStrTab] = [
                    'symbol' => $symbol,
                    'number' => $kodChislo1,
                ];
                $kolStrTab++;
            } else {
                $kodChislo1 += $koef;
            }

            $kodChislo1 = $this->getGenNumber($kodChislo1, 1, 100);
        }

        return $table;
    }
}