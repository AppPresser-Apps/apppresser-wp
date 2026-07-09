/**
 * AppPresser settings pages entry point.
 */
import './index.css';
import { createRoot } from '@wordpress/element';
import AccessibilityApp from './components/AccessibilityApp';
import CookiesApp from './components/CookiesApp';
import CookieScannerApp from './components/CookieScannerApp';
import MaintenanceApp from './components/MaintenanceApp';
import SocialShareApp from './components/SocialShareApp';
import OptionsApp from './components/OptionsApp';
import PopUpsApp from './components/PopUpsApp';

const accessibilityContainer = document.getElementById( 'apppresser-accessibility-root' );
const cookiesContainer = document.getElementById( 'apppresser-cookies-root' );
const scannerContainer = document.getElementById( 'apppresser-cookie-scanner-root' );
const maintenanceContainer = document.getElementById( 'apppresser-maintenance-root' );
const socialShareContainer = document.getElementById( 'apppresser-social-share-root' );
const optionsContainer = document.getElementById( 'apppresser-options-root' );
const popUpsContainer = document.getElementById( 'apppresser-pop-ups-root' );

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

if ( maintenanceContainer ) {
	const root = createRoot( maintenanceContainer );
	root.render( <MaintenanceApp /> );
}

if ( socialShareContainer ) {
	const root = createRoot( socialShareContainer );
	root.render( <SocialShareApp /> );
}

if ( optionsContainer ) {
	const root = createRoot( optionsContainer );
	root.render( <OptionsApp /> );
}

if ( popUpsContainer ) {
	const root = createRoot( popUpsContainer );
	root.render( <PopUpsApp /> );
}
