<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Drive\Laravel\Coroutine\Database\MySQL;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Closure;
use Fiber;
use Generator;
use Illuminate\Database\MySqlConnection;
use Throwable;

use function boolval;
use function in_array;
use function spl_object_hash;
use function trim;

/**
 * @Author cclilshy
 * @Date   2024/8/17 15:48
 */
class Connection extends MySqlConnection
{
    private const ALLOW_OPTIONS = [
        'host',
        'port',
        'user',
        'password',
        'db',
        'charset',
        'collate',
        'compression',
        'local-infile',

        'username',
        'database'
    ];

    /*** @var MysqlConnectionPool */
    private MysqlConnectionPool $pool;
    /**
     * @var MysqlTransaction[]
     */
    private array $fiber2transaction = [];

    /**
     * @param        $pdo
     * @param string $database
     * @param string $tablePrefix
     * @param array  $config
     */
    public function __construct($pdo, string $database = '', string $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
        $dsn = '';
        foreach ($config as $key => $value) {
            if (in_array($key, static::ALLOW_OPTIONS, true)) {
                if (!$value) {
                    continue;
                }

                $key = match ($key) {
                    'username' => 'user',
                    'database' => 'db',
                    default    => $key
                };

                $dsn .= "{$key}={$value} ";
            }
        }
        $config     = MysqlConfig::fromString(trim($dsn));
        $this->pool = new MysqlConnectionPool($config);
    }

    /**
     * @param Closure $callback
     * @param int     $attempts
     *
     * @return mixed
     * @throws Throwable
     */
    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this->getTransaction());
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * @return void
     */
    public function beginTransaction(): void
    {
        if ($this->getTransaction()) {
            return;
        }
        $transaction = $this->pool->beginTransaction();
        if ($fiber = Fiber::getCurrent()) {
            $this->fiber2transaction[spl_object_hash($fiber)] = $transaction;
        } else {
            $this->fiber2transaction['main'] = $transaction;
        }
    }

    /**
     * @return MysqlTransaction|null
     */
    private function getTransaction(): MysqlTransaction|null
    {
        if ($fiber = Fiber::getCurrent()) {
            $key = spl_object_hash($fiber);
        } else {
            $key = 'main';
        }

        if (!$transaction = $this->fiber2transaction[$key] ?? null) {
            return null;
        }

        return $transaction;
    }

    /**
     * @return void
     */
    public function commit(): void
    {
        $key = ($fiber = Fiber::getCurrent()) ? spl_object_hash($fiber) : 'main';
        if (!$transaction = $this->fiber2transaction[$key] ?? null) {
            return;
        }
        $transaction->commit();
        unset($this->fiber2transaction[$key]);
    }

    /**
     * @param $toLevel
     *
     * @return void
     */
    public function rollBack($toLevel = null): void
    {
        $key = ($fiber = Fiber::getCurrent()) ? spl_object_hash($fiber) : 'main';
        if (!$transaction = $this->fiber2transaction[$key] ?? null) {
            return;
        }
        $transaction->rollback();
        unset($this->fiber2transaction[$key]);
    }

    /**
     * @param string $query
     * @param array  $bindings
     *
     * @return bool
     */
    public function statement($query, $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            $statement = $this->getTransaction()?->prepare($query) ?? $this->pool->prepare($query);
            return boolval($statement->execute($this->prepareBindings($bindings)));
        });
    }


    /**
     * 针对数据库运行 select 语句并返回所有结果集。
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return array
     */
    public function selectResultSets($query, $bindings = [], $useReadPdo = true): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $statement = $this->getPoolStatement($query);
            $result    = $statement->execute($this->prepareBindings($bindings));
            $sets      = [];

            while ($result = $result->getNextResult()) {
                $sets[] = $result;
            }

            return $sets;
        });
    }

    /**
     * @param string $query
     *
     * @return MysqlStatement
     */
    private function getPoolStatement(string $query): MysqlStatement
    {
        return $this->getTransaction()?->prepare($query) ?? $this->pool->prepare($query);
    }

    /**
     * 针对数据库运行 select 语句并返回一个生成器。
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return Generator
     */
    public function cursor($query, $bindings = [], $useReadPdo = true): Generator
    {
        while ($record = $this->select($query, $bindings, $useReadPdo)) {
            yield $record;
        }
    }

    /**
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $result        = [];
            $selectRequest = $this->getPoolStatement($query)->execute($this->prepareBindings($bindings));
            foreach ($selectRequest as $row) {
                $result[] = $row;
            }

            return $result;
        });
    }

    /**
     * 运行 SQL 语句并获取受影响的行数。
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     */
    public function affectingStatement($query, $bindings = []): int
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }
            // 对于更新或删除语句，我们想要获取受影响的行数
            // 通过该语句并将其返回给开发人员。我们首先需要
            // 执行该语句，然后我们将使用 PDO 来获取受影响的内容。
            $statement = $this->getPoolStatement($query);
            $result    = $statement->execute($this->prepareBindings($bindings));
            $this->recordsHaveBeenModified(
                ($count = $result->getRowCount()) > 0
            );
            return $count;
        });
    }

    /**
     * @return void
     */
    public function reconnect()
    {
        //TODO: 使其不作为
    }

    /**
     * @return void
     */
    public function reconnectIfMissingConnection()
    {
        //TODO: 使其不作为
    }
}
