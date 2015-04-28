<?php
namespace grandt\ResizeGif\Debug;

use com\grandt\BinStringStatic;
use Exception;
use grandt\ResizeGif\Files\FileHandler;
use grandt\ResizeGif\Structure\AbstractExtensionBlock;
use grandt\ResizeGif\Structure\ApplicationExtension;
use grandt\ResizeGif\Structure\CommentExtension;
use grandt\ResizeGif\Structure\GraphicControlExtension;
use grandt\ResizeGif\Structure\Header;
use grandt\ResizeGif\Structure\ImageDescriptor;
use grandt\ResizeGif\Structure\LogicalScreenDescriptor;
use grandt\ResizeGif\Structure\PlainTextExtension;

/**
 * Open, and dump details of a GIF file.
 *
 * License: GNU LGPL 2.1.
 *
 * @author    A. Grandt <php@grandt.com>
 * @copyright 2015 A. Grandt
 * @license   GNU LGPL 2.1
 * @version   1.0.0
 */
class DebugGif {
    public static function dumpGif($file) {
        $fh = new FileHandler();
        $frameCount = 0;

        echo "Reading '$file'\n";
        try {
            $fh->openFile($file);
        } catch (Exception $e) {
            echo 'Caught exception: ', $e->getMessage(), "\n";
            exit;
        }

        echo "\n\n------------------------------------------------------------------------\n";
        echo "--- HEADER\n";
        echo "------------------------------------------------------------------------\n\n";
        $header = new Header($fh);
        self::dumpHeader($header);

        if ($header->signature !== "GIF" && $header->version !== "87a" && $header->version !== "89a") {
            $fh->closeFile();
            throw new Exception("Not a gif file.");
        }

        echo "\n\n------------------------------------------------------------------------\n";
        echo "--- LOGICAL SCREEN DESCTIPTOR\n";
        echo "------------------------------------------------------------------------\n\n";
        $lsd = new LogicalScreenDescriptor($fh);
        self::dumpLogicalScreenDescriptor($lsd);

        while (!$fh->isEOF()) {
            switch (ord($fh->peekByte())) {
                case AbstractExtensionBlock::CONTROL_EXTENSION:
                    echo "\n\n------------------------------------------------------------------------\n";
                    echo "--- EXTENSION BLOCK\n";
                    echo "------------------------------------------------------------------------\n\n";
                    self::dumpExtensionBlock($fh, $frameCount);
                    break;
                case AbstractExtensionBlock::CONTROL_IMAGE:
                    $frameCount++;
                    echo "\n\n------------------------------------------------------------------------\n";
                    echo "--- FRAME " . $frameCount . "\n";
                    echo "------------------------------------------------------------------------\n\n";
                    $idb = new ImageDescriptor($fh);
                    self::dumpImageDescriptor($idb);
                    break;
                case AbstractExtensionBlock::CONTROL_TRAILER:
                    echo "\n\n------------------------------------------------------------------------\n";
                    echo "--- TRAILER FOUND - END OF IMAGE.\n";
                    echo "--- FRAMES " . $frameCount . "\n";
                    echo "------------------------------------------------------------------------\n\n";
                    $fh->seekForward(1);
                    break;
                case AbstractExtensionBlock::CONTROL_TERMINATOR:
                    $fh->seekForward(1);
                    break;
                default:
                    $c = $fh->readByte();
                    echo "\n\n------------------------------------------------------------------------\n";
                    echo "--- UNKNOWN CODE: 0x" . bin2hex($c) . " (" . ord($c) . ")\n";
                    echo "------------------------------------------------------------------------\n\n";

            }
        }
        echo "\n";

        $fh->closeFile();
    }

    /**
     * @param FileHandler $fh
     * @param int $frameCount
     */
    public static function dumpExtensionBlock($fh, &$frameCount) {

        $fh->seekForward(1);
        $blockLabel = $fh->peekByte();

        switch (ord($blockLabel)) {
            case AbstractExtensionBlock::LABEL_APPLICATION:
                $adb = new ApplicationExtension($fh);
                self::dumpApplicationExtensionBlock($adb);

                break;
            case AbstractExtensionBlock::LABEL_COMMENT:
                $ceb = new CommentExtension($fh);
                self::dumpCommentExtensionBlock($ceb);

                break;
            case AbstractExtensionBlock::LABEL_GRAPHICS_CONTROL:
                $gce = new GraphicControlExtension($fh);
                $frameCount++;
                self::dumpGraphicControlExtensionBlock($gce, $frameCount);

                break;
            case AbstractExtensionBlock::LABEL_PLAIN_TEXT:
                $pte = new PlainTextExtension($fh);
                self::dumpPlainTextExtensionBlock($pte);

                break;
            case AbstractExtensionBlock::CONTROL_TRAILER:
                break;
            default:
                $fh->seekForward(1);
                echo "Unknown Extension block found.......: 0x" . bin2hex($blockLabel) . " (" . ord($blockLabel) . ")\n";
                while (!$fh->compareByte("\x00")) {
                    $fh->seekForward(1);
                }
        }

        if ($fh->compareByte("\x00")) {
            $fh->readByte();
        }
    }

    /**
     * @param bool $data
     * @return string
     */
    public static function echoYN($data) {
        return $data ? "Y" : "N";
    }

    /**
     * @param PlainTextExtension $pte
     */
    public static function dumpPlainTextExtensionBlock($pte) {
        echo "Plain Text Extension................: PTE 0x01 (1)\n";

        echo "  - pteBlockLength..................: " . $pte->blockLength . "\n";
        echo "  - pteTextGridLeftPosition.........: " . $pte->textGridLeftPosition . "\n";
        echo "  - pteTextGridTopPosition..........: " . $pte->textGridTopPosition . "\n";
        echo "  - pteTextGridWidth................: " . $pte->textGridWidth . "\n";
        echo "  - pteTextGridHeight...............: " . $pte->textGridHeight . "\n";
        echo "  - pteCharacterCellWidth...........: " . $pte->characterCellWidth . "\n";
        echo "  - pteCharacterCellHeight..........: " . $pte->characterCellHeight . "\n";
        echo "  - pteTextFGColorIndex.............: " . $pte->textFGColorIndex . "\n";
        echo "  - pteTextBGColorIndex.............: " . $pte->textBGColorIndex . "\n";

        echo "  - sub blocks total length.........: " . (BinStringStatic::_strlen($pte->dataSubBlocks)) . "\n";
    }

    /**
     * @param GraphicControlExtension $gce
     * @param $frameCount
     */
    public static function dumpGraphicControlExtensionBlock($gce, $frameCount) {
        /**
         * Disposal Method Values:
         * 0 -   No disposal specified. The decoder is not required to take any action.
         * 1 -   Do not dispose. The graphic is to be left in place.
         * 2 -   Restore to background color. The area used by the graphic must be restored to the background color.
         * 3 -   Restore to previous. The decoder is required to restore the area overwritten by the graphic with what was there prior to rendering the graphic.
         * 4-7 -   To be defined.
         */
        $disposalMethod = array(
            0 => "No disposal specified",
            1 => "Do not dispose",
            2 => "Restore to background color",
            3 => "Restore to previous",
            4 => "To be defined",
            5 => "To be defined",
            6 => "To be defined",
            7 => "To be defined");

        echo "Graphic Control Extension...........: GCE 0xF9 (249)\n";


        echo "  - gceBlockLength..................: " . $gce->blockLength . "\n";
        echo "  - gcePackedFields.................: \n";
        echo "    - gceReserved...................: " . $gce->reserved . "\n";
        echo "    - gceDisposal...................: " . $gce->disposalMethod . " (" . $disposalMethod[$gce->disposalMethod] . ")\n";
        echo "    - gceUserInputFlag..............: " . self::echoYN($gce->userInputFlag) . "\n";
        echo "    - gceTransparentColorFlag.......: " . self::echoYN($gce->transparentColorFlag) . "\n";
        echo "  - gceDelayTime....................: " . $gce->delayTime . "\n";
        echo "  - gceTransparentColorIndex........: " . $gce->transparentColorIndex . "\n";

        echo "\n--- Image Descriptor (FRAME) " . $frameCount . "\n\n";
        self::dumpImageDescriptor($gce->imageDescriptor);
    }

    /**
     * @param CommentExtension $ceb
     */
    public static function dumpCommentExtensionBlock($ceb) {
        echo " Comment Extension Block............: CEB 0xFE (254)\n";

        echo "  - sub blocks total length.........: " . (BinStringStatic::_strlen($ceb->dataSubBlocks)) . "\n";
    }

    /**
     * @param ApplicationExtension $adb
     */
    public static function dumpApplicationExtensionBlock($adb) {
        echo "Application Extension Block.........: AEB 0xFF (255)\n";

        echo "  - adbBlockLength..................: " . $adb->blockLength . "\n";
        echo "  - adbApplicationIdentifier........: " . $adb->applicationIdentifier . "\n";
        echo "  - adbApplicationAuthenticationCode: " . $adb->applicationAuthenticationCode . "\n";
        echo "  - sub blocks total length.........: " . (BinStringStatic::_strlen($adb->dataSubBlocks)) . "\n";
    }

    /**
     * @param ImageDescriptor $idb
     */
    public static function dumpImageDescriptor($idb) {
        echo "idbScreenLeftPos....................: " . $idb->screenLeftPos . "\n";
        echo "idbScreenRightPos...................: " . $idb->screenTopPos . "\n";
        echo "idbScreenWidth......................: " . $idb->screenWidth . "\n";
        echo "idbScreenHeight.....................: " . $idb->screenHeight . "\n";
        echo "idbPackedFields.....................: \n";
        echo "  - idbColorTableFlag...............: " . self::echoYN($idb->colorTableFlag) . "\n";
        echo "  - idbInterlaceFlag................: " . self::echoYN($idb->interlaceFlag) . "\n";
        echo "  - idbSortFlag.....................: " . self::echoYN($idb->sortFlag) . "\n";
        echo "  - reserved........................: " . $idb->reserved . " (0x" . dechex($idb->reserved) . " > " . decbin($idb->reserved) . ")\n";
        echo "  - idbColorTableSize...............: " . $idb->colorTableSize . "\n";
        echo "\n";
        echo "idb Color Table.....................: ";

        if ($idb->colorTableFlag && $idb->colorTableSize > 0) {
            echo "LCT present.\n";
        } else {
            echo "No LCT.\n";
        }
        echo "\n";
        echo "Image Data..........................: " . "\n";
        echo "  - lzwImageMinCodeSize.............: " . ord($idb->lzwMinCodeSize) . "\n";
        echo "  - lzwImageDataLength..............: " . (BinStringStatic::_strlen($idb->dataSubBlocks) + 1) . "\n";
    }

    /**
     * @param LogicalScreenDescriptor $lsd
     */
    public static function dumpLogicalScreenDescriptor($lsd) {
        echo "lsdScreenWidth......................: " . $lsd->screenWidth . "\n";
        echo "lsdScreenHeight.....................: " . $lsd->screenHeight . "\n";
        echo "lsdPackedFields.....................: \n";
        echo "  - lsdGlobalColorTable.............: " . self::echoYN($lsd->colorTableFlag) . "\n";
        echo "  - lsdColorResolution..............: " . $lsd->colorResolution . "\n";
        echo "  - lsdSortFlag.....................: " . self::echoYN($lsd->sortFlag) . "\n";
        echo "  - lsdGlobalColorTableSize.........: " . $lsd->colorTableSize . "\n";
        echo "lsdBGColorIndex.....................: " . $lsd->bgColorIndex . "\n";
        echo "lsdPixelAspectRatio.................: " . $lsd->pixelAspectRatio . "\n";

        echo "\n";
        echo "Global Color Table..................: ";

        if ($lsd->colorTableFlag && $lsd->colorTableSize > 0) {
            echo "GCT present.\n";
        } else {
            echo "No GCT.\n";
        }
    }

    /**
     * @param Header $header
     */
    public static function dumpHeader($header) {
        echo "Header data signature...............: " . $header->signature . "\n";
        echo "Header data version.................: " . $header->version . "\n";
    }

    /**
     * @param string $file
     */
    public static function dumpFileHexBlock($file) {
        echo "Reading '$file'\n";

        $fh = new FileHandler();

        try {
            $fh->openFile($file);
        } catch (Exception $e) {
            echo 'Caught exception: ', $e->getMessage(), "\n";
            exit;
        }

        HexBlock::printBlock($fh, $fh->getLength(), false);
        echo "\n";
        $fh->closeFile();
    }
}
