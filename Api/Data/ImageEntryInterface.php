<?php
/**
 * Copyright © Nacento
 */
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Interface for media gallery entry data.
 * @api
 */
interface ImageEntryInterface
{
    public const FILE_PATH = 'file_path';
    public const LABEL = 'label';
    public const DISABLED = 'disabled';
    public const POSITION = 'position';
    public const ROLES = 'roles';

    /** @return string */
    public function getFilePath(): string;
    /** @param string $filePath @return $this */
    public function setFilePath(string $filePath): self;

    /** @return string */
    public function getLabel(): string;
    /** @param string $label @return $this */
    public function setLabel(string $label): self;
    
    /** @return bool */
    public function isDisabled(): bool;
    /** @param bool $disabled @return $this */
    public function setDisabled(bool $disabled): self;

    /** @return int */
    public function getPosition(): int;
    /** @param int $position @return $this */
    public function setPosition(int $position): self;

    /** @return string[] */
    public function getRoles(): array;
    /** @param string[] $roles @return $this */
    public function setRoles(array $roles): self;
}