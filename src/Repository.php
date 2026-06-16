<?php

declare(strict_types=1);

namespace Componenta\Cycle;

use Cycle\ORM\Select;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select\Repository as CycleRepository;

abstract class Repository extends CycleRepository
{
    protected ORMInterface $orm;
    protected EntityManagerInterface $em;

    public function __construct(Select $select, ORMInterface $orm, EntityManagerInterface $em)
    {
        $this->orm = $orm;
        parent::__construct($select);
        $this->em = $em;
    }
}
