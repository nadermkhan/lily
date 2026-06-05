<?php

namespace Lily\Support;

class SeoMeta
{
    private string $title = '';
    private string $description = '';
    private array $keywords = [];

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function addKeyword(string $keyword): self
    {
        $this->keywords[] = $keyword;
        return $this;
    }

    public function render(): string
    {
        $html = "<title>{$this->title}</title>\n";
        $html .= "<meta name=\"description\" content=\"{$this->description}\">\n";
        
        if (!empty($this->keywords)) {
            $keywordsStr = implode(', ', $this->keywords);
            $html .= "<meta name=\"keywords\" content=\"{$keywordsStr}\">\n";
        }
        
        return $html;
    }
}
