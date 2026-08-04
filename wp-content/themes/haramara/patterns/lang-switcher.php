<?php
/**
 * Title: Selector de idioma
 * Slug: haramara/lang-switcher
 * Categories: haramara-parts
 * Description: Conmutador ES · EN. Enlaza la página actual en el otro idioma; requiere el módulo de idioma de haramara-core (con el plugin inactivo no imprime nada).
 * Keywords: idioma, language, es, en, switcher
 * Inserter: yes
 */

if ( ! class_exists( '\Haramara\Core\I18n\SiteLanguage' ) ) {
	return;
}

$haramara_lang_current = \Haramara\Core\I18n\SiteLanguage::current();
$haramara_lang_es      = \Haramara\Core\I18n\SiteLanguage::url_for( 'es' );
$haramara_lang_en      = \Haramara\Core\I18n\SiteLanguage::url_for( 'en' );
?>
<!-- wp:html -->
<nav class="hm-lang" aria-label="Idioma / Language">
	<a href="<?php echo esc_url( $haramara_lang_es ); ?>" hreflang="es" lang="es"<?php echo 'es' === $haramara_lang_current ? ' aria-current="true" class="is-current"' : ''; ?>>ES</a><span aria-hidden="true">·</span><a href="<?php echo esc_url( $haramara_lang_en ); ?>" hreflang="en" lang="en"<?php echo 'en' === $haramara_lang_current ? ' aria-current="true" class="is-current"' : ''; ?>>EN</a>
</nav>
<!-- /wp:html -->
