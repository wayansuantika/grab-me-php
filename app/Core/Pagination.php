<?php

declare(strict_types=1);

namespace App\Core;

class Pagination
{
    private int $currentPage;
    private int $perPage;
    private int $total;
    private int $totalPages;
    private int $offset;

    public function __construct(int $total, int $perPage = 50, int $currentPage = 1)
    {
        $this->total = max(0, $total);
        $this->perPage = max(1, min($perPage, 100)); // Cap at 100 per page
        $this->currentPage = max(1, $currentPage);
        $pages = (int) ceil($this->total / $this->perPage);
        $this->totalPages = $pages;
        $this->offset = ($this->currentPage - 1) * $this->perPage;
    }

    /**
     * Get offset for database query
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Get limit for database query
     */
    public function getLimit(): int
    {
        return $this->perPage;
    }

    /**
     * Get current page number
     */
    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get total number of records
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Get total pages
     */
    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    /**
     * Get per-page limit
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * Check if there's a next page
     */
    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->totalPages;
    }

    /**
     * Check if there's a previous page
     */
    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * Get next page number
     */
    public function getNextPage(): ?int
    {
        return $this->hasNextPage() ? $this->currentPage + 1 : null;
    }

    /**
     * Get previous page number
     */
    public function getPreviousPage(): ?int
    {
        return $this->hasPreviousPage() ? $this->currentPage - 1 : null;
    }

    /**
     * Get metadata array for API response
     */
    public function getMetadata(): array
    {
        return [
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->totalPages,
            'has_next' => $this->hasNextPage(),
            'has_previous' => $this->hasPreviousPage(),
            'next_page' => $this->getNextPage(),
            'previous_page' => $this->getPreviousPage(),
        ];
    }

    /**
     * Create from query parameters
     */
    public static function fromRequest(int $total, int $defaultPerPage = 50): self
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min((int) ($_GET['per_page'] ?? $defaultPerPage), 100));
        return new self($total, $perPage, $page);
    }
}
