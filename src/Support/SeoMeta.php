<?php

namespace Lily\Support;

/**
 * Helper class to build and render SEO meta tags.
 */
class SeoMeta
{
    /**
     * The page title.
     *
     * @var string
     */
    private string $title = '';

    /**
     * The page description.
     *
     * @var string
     */
    private string $description = '';

    /**
     * The page keywords.
     *
     * @var array
     */
    private array $keywords = [];

    /**
     * Set the page title.
     *
     * @param string $title The title of the page.
     * @return self
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Set the page description.
     *
     * @param string $description The description of the page.
     * @return self
     */
    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Add a keyword to the SEO meta data.
     *
     * @param string $keyword The keyword to add.
     * @return self
     */
    public function addKeyword(string $keyword): self
    {
        $this->keywords[] = $keyword;
        return $this;
    }

    /**
     * Render the SEO meta tags as HTML.
     *
     * @return string
     */
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
