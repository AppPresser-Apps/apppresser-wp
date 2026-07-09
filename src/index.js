/**
 * AppPresser settings pages entry point.
 */
import './index.css';
import { createRoot } from '@wordpress/element';
import AccessibilityApp from './components/AccessibilityApp';
import CookiesApp from './components/CookiesApp';
import CookieScannerApp from './components/CookieScannerApp';

const accessibilityContainer = document.getElementById( 'apppresser-accessibility-root' );
const cookiesContainer = document.getElementById( 'apppresser-cookies-root' );
const scannerContainer = document.getElementById( 'apppresser-cookie-scanner-root' );

if ( accessibilityContainer ) {
	const root = createRoot( accessibilityContainer );
	root.render( <AccessibilityApp /> );
}

if ( cookiesContainer ) {
	const root = createRoot( cookiesContainer );
	root.render( <CookiesApp /> );
}

if ( scannerContainer ) {
	const root = createRoot( scannerContainer );
	root.render( <CookieScannerApp /> );
}
