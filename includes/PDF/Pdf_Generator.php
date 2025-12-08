<?php

namespace AuditTool\PDF;

use Dompdf\Dompdf;
use Dompdf\Options;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pdf_Generator {

    /**
     * Generate a PDF file using Dompdf.
     *
     * @param string $html      Full HTML document (from Markdown_Renderer).
     * @param string $filename  Relative path under wp-uploads where the PDF will be written.
     *
     * @return string Absolute path to the generated PDF file.
     *
     * @throws \RuntimeException On failure to generate or write the PDF.
     */
    public function generate( string $html, string $filename ): string {
        if ( ! function_exists( 'wp_upload_dir' ) ) {
            throw new \RuntimeException( 'WordPress upload directory is not available.' );
        }

        $upload_dir = \wp_upload_dir();

        if ( empty( $upload_dir['basedir'] ) ) {
            throw new \RuntimeException( 'WordPress upload directory is not available.' );
        }

        // Configure Dompdf options to allow remote images (our BrowserShot PNGs).
        $options = new Options();
        $options->set( 'isRemoteEnabled', true );      // allow http/https images
        $options->set( 'isHtml5ParserEnabled', true ); // better handling of modern HTML
        $options->set( 'defaultMediaType', 'screen' ); // render using screen styles
        $options->set( 'defaultFont', 'Helvetica' );

        $dompdf = new Dompdf( $options );

        // Base path so relative URLs (if any) resolve under uploads.
        if ( ! empty( $upload_dir['basedir'] ) ) {
            $dompdf->setBasePath( $upload_dir['basedir'] );
        }

        $dompdf->loadHtml( $html );
        $dompdf->setPaper( 'A4', 'portrait' );
        $dompdf->render();

        $file_path = trailingslashit( $upload_dir['basedir'] ) . ltrim( $filename, '/\\' );

        // Ensure destination directory exists.
        $dir = dirname( $file_path );
        if ( ! is_dir( $dir ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) {
                \wp_mkdir_p( $dir );
            } else {
                @mkdir( $dir, 0755, true );
            }
        }

        $output = $dompdf->output();
        if ( false === file_put_contents( $file_path, $output ) ) {
            throw new \RuntimeException( 'Failed to write PDF to: ' . $file_path );
        }

        return $file_path;
    }
}
