<?php
/**
 * Thin base for every Model: just holds the shared PDO connection so
 * subclasses don't each need their own constructor boilerplate.
 */
abstract class Model
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
}
