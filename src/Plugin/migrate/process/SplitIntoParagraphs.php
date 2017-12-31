<?php

namespace Drupal\atom_migrate\Plugin\migrate\process;

use DOMDocument;
use DOMXpath;
use Drupal\Core\Language\Language;
use Drupal\media\Entity\Media;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Split an HTML blob into paragraphs.
 *
 * This version assumes that the HTML is a sequence of <p> elements. If the <p>
 * tag wraps a single <img> element, then create a Media paragraph. Otherwise,
 * create a Text Area paragraph.
 *
 * Return an array of arrays. The inner arrays are keyed by 'target_id' and
 * 'target_revision_id', suitable for passing into a Paragraph field.
 *
 * Example:
 *
 * @code
 * process:
 *   field_paragraph:
 *     plugin: split_into_paragraphs
 *     source: html_blob
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "split_into_paragraphs"
 * )
 */
class SplitIntoParagraphs extends ProcessPluginBase {

  /**
   * Options for DOMDocument::loadHTML().
   *
   * Do not add a DTD when loading HTML into DOMDocument.
   * @var int
   */
  const DOM_OPTIONS = LIBXML_HTML_NODEFDTD;

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $paragraphs = [];
    $post = new DOMDocument();
    // I hope that $value is always a string.
    $post->loadHTML($value, static::DOM_OPTIONS);

    $html = $post->getElementsByTagName('body')->item(0);
    $current = '';
    foreach ($html->childNodes as $child) {
      $fragment = $post->saveHTML($child);
      // If the current node contains an image tag, then push $current onto the
      // output. Assume there is nothing else interesting in the current node,
      // and push the image onto the output.
      if (!static::hasDescendantTag($fragment, 'img')) {
        $current .= $fragment;
      }
      else {
        if (strlen($current)) {
          $paragraphs[] = static::createTextParagraph($current);
          $current = '';
        }
        $paragraphs[] = static::createMediaParagraph($fragment);
      }
    }
    if (strlen($current)) {
      $paragraphs[] = static::createTextParagraph($current);
    }

    return $paragraphs;
  }

  /**
   * Check whether an HTML blob includes an given tag.
   *
   * @param string $blob
   *   The HTML string to check.
   * @param $tag
   *   The tag name.
   *
   * @return bool
   *   Return TRUE if the blob contains the tag $tag.
   */
  static protected function hasDescendantTag($blob, $tag) {
    // Even when $blob comes from a DOMNode object, I could not get this to work
    // using DOMXpath::query().
    $dom = new DOMDocument();
    $dom->loadHTML($blob, static::DOM_OPTIONS);
    $nodes = $dom->getElementsByTagName($tag);
    return ($nodes->length != 0);
  }

  /**
   * Create a Text Area paragraph.
   *
   * @param string $blob
   *   The HTML string to use as the main text field.
   *
   * @return int[]
   *   An array of entity/revision IDs keyed by 'target_id' and
   *   'target_revision_id'.
   */
  static protected function createTextParagraph($blob) {
    $paragraph = Paragraph::create([
      'type' => 'text_area',
      'field_text_area' => [
        'value'  =>  $blob,
        'format' => 'basic_html'
      ],
    ]);
    $paragraph->save();

    return [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
  }

  /**
   * Create a Media paragraph.
   *
   * Save the file locally, creating a File entity of type image.
   * Create a Media entity of type image referencing that File entity.
   * Create a Media paragraph referencing that Media entity.
   *
   * @param string $blob
   *   The HTML string to use as the main text field. It should contain an <img>
   *   element. If there is more than one, only the first is used.
   *
   * @return int[]
   *   An array of entity/revision IDs keyed by 'target_id' and
   *   'target_revision_id'.
   */
  static protected function createMediaParagraph($blob) {
    $dom = new DOMDocument();
    $dom->loadHTML($blob, static::DOM_OPTIONS);
    $node = $dom->getElementsByTagName('img')->item(0);
    $src = $node->getAttribute('src');
    $alt_text = $node->getAttribute('alt');

    // Download and save the file.
    $destination = 'public://' . date('Y-m');
    $file = system_retrieve_file($src, $destination, TRUE);

    // Create the Media entity.
    $media = Media::create([
      'bundle' => 'image',
      'uid' => '9',
      // 'langcode' => Language::LANGCODE_DEFAULT,
      // 'status' => Media::PUBLISHED,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $alt_text,
      ],
    ]);
    $media->save();

    // Create the Media paragraph entity.
    $paragraph = Paragraph::create([
      'type' => 'media',
      'field_media' => $media->id(),
    ]);
    $paragraph->save();

    return [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
  }

}
