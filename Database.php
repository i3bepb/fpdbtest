<?php

namespace FpDbTest;

use Exception;
use mysqli;
use stdClass;

class Database implements DatabaseInterface
{
    const string DELETE_BLOCK = 'f0dd7b9da342491ab35d9256b248a1e1';

    public function __construct(private mysqli $mysqli)
    {}

    public function buildQuery(string $query, array $args = []): string
    {
        if ($query === '') {
            throw new Exception('Пустой запрос');
        }
        if (!$this->validBlock($query)) {
            throw new Exception('Не правильно указан условный блок в запросе');
        }
        if (empty($args)) {
            return $query;
        }
        $patterns = $this->getParamFromQuery($query);
        if ($patterns === false) {
            throw new Exception('Заданы значения, но в запросе нет кодов вставок');
        }
        $c1 = count($args);
        $c2 = count($patterns);
        if ($c2 !== $c1) {
            throw new Exception('Разное кол-во значений и кодов вставок в запросе');
        }
        for ($i = 0; $i < $c1; $i++) {
            $escapeValue = $this->escapeValue($patterns[$i], $args[$i]);
            $query = $this->strReplaceOneTime($patterns[$i], $escapeValue, $query);
        }
        $query = $this->deleteBlock($query);
        return $query;
    }

    public function skip(): object
    {
        return new stdClass;
    }

    /**
     * Ищем в запросе одно из ниже следующих
     * ? - по типу переданного значения, но допускаются только типы string, int, float, bool (приводится к 0 или 1) и null
     * ?d - конвертация в целое число
     * ?f - конвертация в число с плавающей точкой
     * ?a - массив значений
     * ?# - идентификатор или массив идентификаторов
     *
     * @param string $query
     *
     * @return array|false
     */
    private function getParamFromQuery(string $query): array|false
    {
        if (preg_match_all('/\\?[dfa#]?/', $query, $m)) {
            return $m[0];
        }
        return false;
    }

    /**
     * Экранируем в зависимости от шаблона переменную
     *
     * @param string $pattern
     * @param array | int | float| string $value
     *
     * @return string
     *
     * @throws \Exception
     */
    private function escapeValue(string $pattern, mixed $value): string
    {
        return match ($pattern) {
            '?d' => $this->escapeQuestionD($value),
            '?f' => $this->escapeQuestionF($value),
            '?a' => $this->escapeQuestionA($value),
            '?#' => $this->escapeQuestionSharp($value),
            default => $this->escapeQuestionMark($value),
        };
    }

    /**
     * Вариант ?d
     *
     * @param string|int|float|bool|null|object $value
     *
     * @return string
     *
     * @throws \Exception
     */
    private function escapeQuestionMark(string|int|float|bool|null|object $value): string
    {
        if (is_string($value) && ($value === '')) {
            throw new Exception('Значение пустая строка');
        }
        $type = gettype($value);
        return match ($type) {
            'string' => "'{$value}'",
            'integer' => (string) $value,
            'double' => "{$value}",
            'boolean' => $value ? '1' : '0',
            'NULL' => 'NULL',
            'object' => self::DELETE_BLOCK,
            default => throw new Exception('Значение не предусмотренного типа'),
        };
    }

    /**
     * Вариант ?d
     *
     * @param int|object|null $value
     *
     * @return string
     */
    private function escapeQuestionD(int|object|null $value): string
    {
        if (is_object($value)) {
            return self::DELETE_BLOCK;
        }
        if (is_null($value)) {
            return 'NULL';
        }
        return (string) $value;
    }

    /**
     * Вариант ?f
     *
     * @param float|object|null $value
     *
     * @return string
     */
    private function escapeQuestionF(float|object|null $value): string
    {
        if (is_object($value)) {
            return self::DELETE_BLOCK;
        }
        if (is_null($value)) {
            return 'NULL';
        }
        return (string) $value;
    }

    /**
     * Вариант ?a
     *
     * @param array|object $value
     *
     * @return string
     *
     * @throws \Exception
     */
    private function escapeQuestionA(array|object $value): string
    {
        if (is_object($value)) {
            return self::DELETE_BLOCK;
        }
        $escapeValue = '';
        $firstKey = array_key_first($value);
        if (array_is_list($value)) {
            foreach ($value as $key => $val) {
                if ($key !== $firstKey) {
                    $escapeValue .= ', ';
                }
                $escapeValue .= $this->escapeQuestionMark($val);
            }
        } else {
            foreach ($value as $key => $val) {
                if ($key !== $firstKey) {
                    $escapeValue .= ', ';
                }
                $escapeValue .= $this->escapeQuestionSharp($key) . ' = ' . $this->escapeQuestionMark($val);
            }
        }
        return $escapeValue;
    }

    /**
     * Вариант ?#
     *
     * @param array|string|object $value
     *
     * @return string
     *
     * @throws \Exception
     */
    private function escapeQuestionSharp(array|string|object $value): string
    {
        if (is_object($value)) {
            return self::DELETE_BLOCK;
        }
        if (is_array($value)) {
            $escapeValue = '';
            $firstKey = array_key_first($value);
            foreach ($value as $key => $val) {
                if ($key !== $firstKey) {
                    $escapeValue .= ', ';
                }
                if ($val === '') {
                    throw new Exception('Указатель пустая строка');
                }
                $escapeValue .= "`{$val}`";
            }
            return $escapeValue;
        }
        if ($value === '') {
            throw new Exception('Указатель пустая строка');
        }
        return "`{$value}`";
    }

    /**
     * @param string $search Может быть ?,?d,?a,?f,?#
     * @param string $replace
     * @param string $query
     *
     * @return string
     */
    private function strReplaceOneTime(string $search, string $replace, string $query): string
    {
        return preg_replace('/' . preg_quote($search, '/') . '/', $replace, $query, 1);
    }

    /**
     * @param string $query
     *
     * @return bool
     */
    private function validBlock(string $query): bool
    {
        $c = strlen($query);
        $need = 0;
        for ($i = 0; $i < $c; $i++) {
            if ($query[$i] === '{') {
                $need++;
            }
            if ($query[$i] === '}') {
                $need--;
            }
            if ($need < 0) {
                return false;
            }
        }
        if ($need === 0) {
            return true;
        }
        return false;
    }

    /**
     * @param string $query
     *
     * @return string
     */
    private function deleteBlock(string $query): string
    {
        $query = preg_replace('/\\{[^\\{]*?' . self::DELETE_BLOCK . '[^\\}]*?\\}/i', '', $query);
        return str_replace(['{','}'], '', $query);
    }
}
