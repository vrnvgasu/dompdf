<?php
/**
 * @package dompdf
 * @link    http://dompdf.github.com/
 * @author  Benj Carson <benjcarson@digitaljunkies.ca>
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameReflower;

use Dompdf\Frame;
use Dompdf\FrameDecorator\Block as BlockFrameDecorator;
use Dompdf\FrameDecorator\Inline as InlineFrameDecorator;
use Dompdf\FrameDecorator\Text as TextFrameDecorator;

/**
 * Reflows inline frames
 *
 * @package dompdf
 */
class Inline extends AbstractFrameReflower
{
    /**
     * Inline constructor.
     * @param InlineFrameDecorator $frame
     */
    function __construct(InlineFrameDecorator $frame)
    {
        parent::__construct($frame);
    }

    /**
     * Handle pre-layout of empty inline frames: Add a new line to the block
     * parent and split parent inline frames as necessary.
     *
     * Regular inline frames are re-positioned together with their text-frame
     * (or inline) children as necessary during child reflow. Empty inline
     * frames have no children that could handle the re-positioning, so the
     * decision of wether to move them to a new line needs to be handled
     * separately.
     *
     * @param BlockFrameDecorator $block
     * @return bool Whether an inline ancestor was split before the frame.
     */
    protected function pre_layout_empty(BlockFrameDecorator $block): bool
    {
        $frame = $this->_frame;
        $cb = $frame->get_containing_block();
        $line = $block->get_current_line_box();
        $width = $frame->get_margin_width();

        if ($width > ($cb["w"] - $line->left - $line->w - $line->right)) {
            $block->add_line();

            // Find the appropriate inline ancestor to split
            $child = $frame;
            $p = $child->get_parent();
            while ($p instanceof InlineFrameDecorator && !$child->get_prev_sibling()) {
                $child = $p;
                $p = $p->get_parent();
            }

            if ($p instanceof InlineFrameDecorator) {
                // Split parent and stop current reflow
                $p->split($child);
                return true;
            }
        }

        return false;
    }

    /**
     * @param BlockFrameDecorator|null $block
     */
    function reflow(BlockFrameDecorator $block = null)
    {
        /** @var InlineFrameDecorator */
        $frame = $this->_frame;

        // Check if a page break is forced
        $page = $frame->get_root();
        $page->check_forced_page_break($frame);

        if ($page->is_full()) {
            return;
        }

        $style = $frame->get_style();

        // Generated content
        $this->_set_content();

        // Resolve auto margins
        // https://www.w3.org/TR/CSS21/visudet.html#inline-width
        // https://www.w3.org/TR/CSS21/visudet.html#inline-non-replaced
        if ($style->margin_left === "auto") {
            $style->margin_left = 0;
        }
        if ($style->margin_right === "auto") {
            $style->margin_right = 0;
        }
        if ($style->margin_top === "auto") {
            $style->margin_top = 0;
        }
        if ($style->margin_bottom === "auto") {
            $style->margin_bottom = 0;
        }

        // Add our margin, padding & border to the first and last children
        if (($f = $frame->get_first_child()) && $f instanceof TextFrameDecorator) {
            $f_style = $f->get_style();
            $f_style->margin_left = $style->margin_left;
            $f_style->padding_left = $style->padding_left;
            $f_style->border_left = $style->border_left;
        }

        if (($l = $frame->get_last_child()) && $l instanceof TextFrameDecorator) {
            $l_style = $l->get_style();
            $l_style->margin_right = $style->margin_right;
            $l_style->padding_right = $style->padding_right;
            $l_style->border_right = $style->border_right;
        }

        // Handle empty inline frames
        // `br` frames are handled in `add_frame_to_line` below
        if (!$frame->get_first_child() && $frame->get_node()->nodeName !== "br") {
            // Resolve width, so the margin width can be checked
            $style->width = 0;
            $split = $this->pre_layout_empty($block);

            // Stop current reflow if a parent inline frame was split. Reflow
            // continues via child-reflow loop of split parent
            if ($split) {
                return;
            }
        }

        $frame->position();
        $cb = $frame->get_containing_block();

        if ($block) {
            $block->add_frame_to_line($frame);
        }

        // Set the containing blocks and reflow each child.  The containing
        // block is not changed by line boxes.
        foreach ($frame->get_children() as $child) {
            $child->set_containing_block($cb);
            $child->reflow($block);

            // Stop reflow of subsequent children if the frame was split within
            // child reflow
            if ($child->get_parent() !== $frame) {
                break;
            }
        }

        // Handle relative positioning
        foreach ($frame->get_children() as $child) {
            $this->position_relative($child);
        }
    }
}
