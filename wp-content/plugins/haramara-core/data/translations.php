<?php
/**
 * ES → EN dictionary for the /en/ front end.
 *
 * Applied once over the rendered page by I18n\SiteLanguage (strtr(), which
 * prefers the longest key at each position — whole sentences must therefore
 * appear as their own keys even when they contain shorter keys). Keys must
 * match the rendered HTML text exactly, including accents and punctuation.
 *
 * Dish names are translated descriptively; house names (Chill, Groove,
 * Funky, Guayabito, Orí, crème brûlée) stay as they are spoken at the bar.
 *
 * @package Haramara\Core
 * @return array<string,string>
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	/* ------------------------------------------------------------ Header */
	'Saltar al contenido'                                  => 'Skip to content',
	'Navegación principal'                                 => 'Main navigation',
	'Navegación legal'                                     => 'Legal navigation',
	'>Carta<'                                              => '>Menu<',
	'>Historia<'                                           => '>Story<',
	'>Eventos<'                                            => '>Events<',
	'>Lealtad<'                                            => '>Loyalty<',
	'>Visítanos<'                                          => '>Visit us<',
	'>Ordenar<'                                            => '>Order<',

	/* ------------------------------------------------------ Hero — 08:00 */
	'Café. Fuego. Ritual.'                                 => 'Coffee. Fire. Ritual.',
	'Una barra de especialidad escondida en Cuernavaca. Creamos a través de procesos manuales: café de origen, masa madre viva y el tiempo que cada cosa pide.' => 'A specialty bar hidden in Cuernavaca. We create through manual processes: single-origin coffee, living sourdough, and the time each thing asks for.',
	'Ordenar para recoger'                                 => 'Order for pickup',
	'Cómo llegar'                                          => 'How to find us',
	'Miércoles a lunes · 8:00–20:00'                       => 'Wednesday to Monday · 8:00–20:00',

	/* ---------------------------------------------------- 09:30 el horno */
	'El horno'                                             => 'The oven',
	'Masa madre, laminado a mano'                          => 'Sourdough, laminated by hand',
	'El pan sale en tandas cortas durante la mañana: fermentaciones largas, mantequilla laminada a mano y un horno que no se apura. Lo que ves abajo se sirve tal cual, en plato de acero, sobre mármol negro.' => 'Bread comes out in short batches through the morning: long fermentations, butter laminated by hand, and an oven that is never rushed. What you see below is served exactly like this, on a steel plate, over black marble.',
	'Ver la carta completa'                                => 'See the full menu',

	/* ------------------------------------------------- 12:00 filtrados */
	'La barra de filtrados'                                => 'The filter bar',
	'Un mismo grano, tres lecturas'                        => 'One bean, three readings',
	'La selección de la barra cambia con frecuencia. Pregunta qué se está filtrando hoy y elige a qué profundidad quieres llegar.' => 'The bar’s selection changes often. Ask what is being filtered today and choose how deep you want to go.',
	'El punto de partida.'                                 => 'The starting point.',
	'Un paso más profundo.'                                => 'A step deeper.',
	'Para los curiosos.'                                   => 'For the curious.',
	'Espresso $60 · Cold brew $70 · La barra también trabaja Chemex y sifón.' => 'Espresso $60 · Cold brew $70 · The bar also works Chemex and siphon.',

	/* ----------------------------------------------------- 16:00 la cueva */
	'La cueva'                                             => 'The cave',
	'«Una barra de café de especialidad adentro de una cueva.»' => '“A specialty coffee bar inside a cave.”',
	'Así la describen quienes la encuentran.'              => 'That is how the people who find it describe it.',
	'Piedra, mármol negro, acero y latón. Sombra suficiente para quedarse.' => 'Stone, black marble, steel and brass. Enough shadow to stay a while.',

	/* ------------------------------------------------------- 18:30 ocaso */
	'La hora dorada'                                       => 'The golden hour',
	'Lo dulce llega con la tarde'                          => 'Sweetness arrives with the afternoon',
	'Cada mes pasa algo distinto en la barra: cata, música, una mesa larga.' => 'Something different happens at the bar every month: a tasting, music, one long table.',
	'Eventos del mes'                                      => 'This month’s events',

	/* ------------------------------------------------------ 20:00 cierre */
	'>Cerramos<'                                           => '>We close<',
	'Martes descansamos.'                                  => 'On Tuesdays, we rest.',
	'>Visítanos</h2>'                                      => '>Visit us</h2>',
	'Escríbenos por WhatsApp'                              => 'Write to us on WhatsApp',
	'Abrir en Maps'                                        => 'Open in Maps',
	'Cada visita deja huella'                              => 'Every visit leaves a mark',
	'Lealtad Haramara vive en un código QR: cada visita suma y los canjes se aplican en barra. Sin tarjetas que se pierden.' => 'Haramara Loyalty lives in a QR code: every visit counts and rewards are applied at the bar. No cards to lose.',
	'Conocer Lealtad Haramara'                             => 'About Haramara Loyalty',

	/* ------------------------------------------------------------ Footer */
	'Café de especialidad y pan de masa madre, hechos con procesos manuales.' => 'Specialty coffee and sourdough bread, made through manual processes.',
	'© Haramara · Cuernavaca, Morelos · Parte de la casa de Cocina Suiza, Pacífica y Malva' => '© Haramara · Cuernavaca, Morelos · Part of the house of Cocina Suiza, Pacífica and Malva',
	'© Haramara · Tulipán 302, esq. Hule, Cuernavaca · Miércoles a lunes, 8:00–20:00' => '© Haramara · Tulipán 302, corner of Hule, Cuernavaca · Wednesday to Monday, 8:00–20:00',
	'Aviso de privacidad'                                  => 'Privacy notice',
	'>Términos<'                                           => '>Terms<',

	/* ------------------------------------------------------- Carta page */
	'>La carta</h1>'                                       => '>The menu</h1>',
	'Lo que se sirve en barra, tal cual, con sus precios. Ordena en línea y recoge cuando esté listo.' => 'What is served at the bar, exactly as it is, prices included. Order online and pick up when it’s ready.',
	'Nuestros cafés'                                       => 'Our coffees',
	'>Salados<'                                            => '>Savory<',
	'>Especiales<'                                         => '>Specials<',
	'La selección de filtrados cambia con frecuencia; pregunta en barra qué se está trabajando hoy.' => 'The filter selection changes often; ask at the bar what is being worked today.',
	'Los especiales rotan cada mes. También servimos kombucha Orí.' => 'Specials rotate monthly. We also serve Orí kombucha.',

	/* ----------------------------------------------- Dish & drink names */
	'Filtrado · Chill'                                     => 'Filter · Chill',
	'Filtrado · Groove'                                    => 'Filter · Groove',
	'Filtrado · Funky'                                     => 'Filter · Funky',
	'Pudding chía'                                         => 'Chia pudding',
	'Croissant de ventresca de atún'                       => 'Tuna belly croissant',
	'Croissant de burrata y jamón serrano'                 => 'Burrata and serrano ham croissant',
	'Bagel de trucha ahumada y alcaparra crocante'         => 'Smoked trout bagel with crispy capers',
	'Pretzel con encurtidos y selva negra'                 => 'Pretzel with pickles and Black Forest ham',
	'Naan cuatro quesos con piñones y hot honey trufada'   => 'Four-cheese naan with pine nuts and truffled hot honey',
	'Laminado de queso gouda y jamón de pavo'              => 'Laminated pastry with gouda and turkey ham',
	'Pie matcha'                                           => 'Matcha pie',
	'Galletas de macadamia con toffee'                     => 'Macadamia toffee cookies',

	/* -------------------------------------- Product copy (Woo catalog) */
	'Extracción del día, en barra.'                        => 'The day’s extraction, at the bar.',
	'Nuestro espresso del día, extraído al momento.'       => 'Our espresso of the day, pulled to order.',
	'Extracción en frío, servido al tiempo o con hielo.'   => 'Cold-extracted, served straight or over ice.',
	'Café extraído en frío durante horas, no minutos.'     => 'Coffee extracted cold over hours, not minutes.',
	'El punto de partida de la escalera de filtrados.'     => 'The starting point of the filter ladder.',
	'Un paso más profundo en la escalera de filtrados.'    => 'A step deeper on the filter ladder.',
	'Para los curiosos. Lo más expresivo de la barra.'     => 'For the curious. The most expressive cup at the bar.',
	'La selección de la barra cambia con frecuencia; pregunta qué se está filtrando hoy.' => 'The bar’s selection changes often; ask what is being filtered today.',
	'Chía, fruta de temporada y granola de la casa.'       => 'Chia, seasonal fruit and house granola.',
	'Pudding de chía con fruta fresca y granola de la casa.' => 'Chia pudding with fresh fruit and house granola.',
	'Laminado de la casa con ventresca de atún.'           => 'House laminated pastry with tuna belly.',
	'Croissant laminado a mano, relleno de ventresca de atún.' => 'Hand-laminated croissant filled with tuna belly.',
	'Laminado de la casa, burrata y jamón serrano.'        => 'House laminated pastry, burrata and serrano ham.',
	'Croissant laminado a mano con burrata y jamón serrano.' => 'Hand-laminated croissant with burrata and serrano ham.',
	'Bagel de la casa, trucha ahumada, alcaparra crocante.' => 'House bagel, smoked trout, crispy capers.',
	'Bagel de la casa con trucha ahumada y alcaparras crocantes.' => 'House bagel with smoked trout and crispy capers.',
	'Pretzel de la casa, encurtidos y jamón selva negra.'  => 'House pretzel, pickles and Black Forest ham.',
	'Pretzel de la casa servido con encurtidos, mantequilla y jamón selva negra — la herencia de la casa suiza de la familia.' => 'House pretzel served with pickles, butter and Black Forest ham — the inheritance of the family’s Swiss house.',
	'Naan de la casa, cuatro quesos, piñones y miel picante trufada.' => 'House naan, four cheeses, pine nuts and truffled hot honey.',
	'Naan de la casa con cuatro quesos, piñones y hot honey trufada.' => 'House naan with four cheeses, pine nuts and truffled hot honey.',
	'Laminado de la casa con gouda y jamón de pavo.'       => 'House laminated pastry with gouda and turkey ham.',
	'Laminado de la casa con queso gouda y jamón de pavo.' => 'House laminated pastry with gouda cheese and turkey ham.',
	'Nuestra pieza más pedida: crema quemada al momento.'  => 'Our most requested piece: custard torched to order.',
	'Crème brûlée sobre laminado de la casa, quemada al momento.' => 'Crème brûlée over house laminated pastry, torched to order.',
	'Pie de la casa con matcha.'                           => 'House pie with matcha.',
	'La pieza de guayaba de la casa.'                      => 'The house guava piece.',
	'El guayabito de la casa: pieza dulce de guayaba.'     => 'The house guayabito: a sweet guava piece.',
	'Galleta de macadamia con toffee de la casa.'          => 'House macadamia cookie with toffee.',
	'Galletas de macadamia con toffee.'                    => 'Macadamia cookies with toffee.',

	/* -------------------------------------------------- Ordering chrome */
	'Ordena para recoger'                                  => 'Order for pickup',
	'Elige tus piezas y paga desde el teléfono. Todo se recoge en barra, en Tulipán 302.' => 'Choose your pieces and pay from your phone. Everything is picked up at the bar, at Tulipán 302.',
	'Por ahora no hay nada disponible en línea. La barra abre de miércoles a lunes, de 8:00 a 20:00.' => 'Nothing is available online right now. The bar opens Wednesday to Monday, 8:00 to 20:00.',
	'Ordena hoy, recoge en barra'                          => 'Order today, pick up at the bar',
	'Elige tus piezas, paga desde el teléfono y pasa por ellas cuando estén listas.' => 'Choose your pieces, pay from your phone, and come by when they’re ready.',
	'Café de especialidad y masa madre. Todo se prepara para recoger en barra — sin envíos.' => 'Specialty coffee and sourdough. Everything is prepared for pickup at the bar — no delivery.',
	'No hay productos disponibles por ahora. Vuelve pronto o síguenos en' => 'No products are available right now. Come back soon or follow us on',
	'No hay productos en esta categoría por ahora.'        => 'No products in this category right now.',
	'Ordena y recoge en barra. Elige tu horario en el carrito.' => 'Order and pick up at the bar. Choose your time in the cart.',
	'Ordena y recoge en barra. Elige tu horario de recogida antes de pagar.' => 'Order and pick up at the bar. Choose your pickup time before paying.',
	'También podría gustarte'                              => 'You might also like',
	'Tu carrito está vacío'                                => 'Your cart is empty',
	'>Tu carrito<'                                         => '>Your cart<',
	'Aún no has agregado nada. Explora la carta: café, laminados y masa madre.' => 'You haven’t added anything yet. Explore the menu: coffee, laminated pastry and sourdough.',
	'Explorar la carta'                                    => 'Explore the menu',
	'Finalizar pedido'                                     => 'Complete order',
	'Volver a la carta'                                    => 'Back to the menu',
	'Te enviaremos un mensaje cuando tu pedido esté listo para recoger. Gracias por apoyar el oficio.' => 'We’ll message you when your order is ready for pickup. Thank you for supporting the craft.',
	'Paga al recoger'                                      => 'Pay at pickup',
	'Efectivo o tarjeta al recoger en barra.'              => 'Cash or card when you pick up at the bar.',
	'Tu pedido se paga al recogerlo en Tulipán 302.'       => 'Your order is paid for at pickup, at Tulipán 302.',

	/* ----------------------------------------------------- Historia page */
	'Creamos a través de procesos manuales.'               => 'We create through manual processes.',
	'Haramara nace de una casa con más de treinta años de oficio en Cuernavaca: la misma familia gastronómica de Cocina Suiza, Pacífica y Malva. Aquí ese oficio se concentra en dos cosas — el café de especialidad y el pan de masa madre — y en el tiempo que ambos piden.' => 'Haramara comes from a house with more than thirty years of craft in Cuernavaca: the same gastronomic family behind Cocina Suiza, Pacífica and Malva. Here that craft narrows to two things — specialty coffee and sourdough bread — and the time both of them ask for.',
	'Fermentaciones controladas, laminado a mano, métodos de barra que se preparan uno a uno. Nada aquí está hecho en serie, y se nota.' => 'Controlled fermentations, hand lamination, bar methods prepared one by one. Nothing here is mass-made, and you can tell.',

	/* ------------------------------------------------------ Eventos page */
	'Cada mes pasa algo distinto en la barra. Aparta tu lugar por WhatsApp.' => 'Something different happens at the bar every month. Reserve your spot on WhatsApp.',
	'Nombre del evento (edítame)'                          => 'Event name (edit me)',
	'Hora · Detalle breve · Cupo'                          => 'Time · Short detail · Capacity',
	'Apartar lugar por WhatsApp'                           => 'Reserve a spot on WhatsApp',

	/* ------------------------------------------------------ Lealtad page */
	'Cada visita deja huella.'                             => 'Every visit leaves a mark.',
	'Lealtad Haramara vive en un código QR, no en una tarjeta que se pierde. Tu cuenta guarda tus sellos y tus canjes; la barra hace el resto.' => 'Haramara Loyalty lives in a QR code, not on a card that gets lost. Your account keeps your stamps and redemptions; the bar does the rest.',
	'Crea tu cuenta'                                       => 'Create your account',
	'Un minuto, desde el teléfono. Tu QR aparece en tu cuenta.' => 'One minute, from your phone. Your QR appears in your account.',
	'Muestra tu QR en barra'                               => 'Show your QR at the bar',
	'Cada visita suma. Sin apps que instalar, sin fricción.' => 'Every visit counts. No apps to install, no friction.',
	'Canjea cuando toque'                                  => 'Redeem when it’s time',
	'Tus recompensas se aplican en barra, directamente desde tu cuenta.' => 'Your rewards are applied at the bar, straight from your account.',
	'Crear mi cuenta'                                      => 'Create my account',
	'Ya tengo cuenta'                                      => 'I already have an account',

	/* ---------------------------------------------------- Visítanos page */

	/* -------------------------------------------------- Blog / archive */
	'>Diario<'                                             => '>Journal<',
	'Notas de café, fermentación y oficio desde la barra.' => 'Notes on coffee, fermentation and craft from the bar.',
	'Café, fuego y masa madre — apuntes de la casa.'       => 'Coffee, fire and sourdough — notes from the house.',
	'Aún no hay entradas. Vuelve pronto.'                  => 'No entries yet. Come back soon.',
	'No hay entradas en este archivo todavía.'             => 'No entries in this archive yet.',
	'Seguir leyendo'                                       => 'Keep reading',
	'>Anterior<'                                           => '>Previous<',
	'>Siguiente<'                                          => '>Next<',

	/* --------------------------------------------------- 404 and search */
	'Esta página no está en la carta'                      => 'This page is not on the menu',
	'No encontramos lo que buscabas. Prueba con la carta o busca de nuevo.' => 'We couldn’t find what you were looking for. Try the menu, or search again.',
	'Ver la carta'                                         => 'See the menu',
	'Volver al inicio'                                     => 'Back to the start',
	'Buscar en Haramara…'                                  => 'Search Haramara…',
	'>Buscar<'                                             => '>Search<',
	'No encontramos resultados para tu búsqueda. Prueba con otras palabras o explora' => 'We found no results for your search. Try other words, or explore',
	'>la carta</a>'                                        => '>the menu</a>',

	/* --------------------------------------------------------- Legal */
	'Documento en preparación. El aviso de privacidad definitivo será publicado por Haramara antes del lanzamiento.' => 'Document in preparation. Haramara’s final privacy notice will be published before launch.',
	'Documento en preparación. Los términos de servicio definitivos serán publicados por Haramara antes del lanzamiento.' => 'Document in preparation. Haramara’s final terms of service will be published before launch.',

	/* ----------------------------------------------- SEO titles & metas */
	'Haramara — café de especialidad y masa madre en Cuernavaca.' => 'Haramara — specialty coffee and sourdough in Cuernavaca.',
	'Una barra de café de especialidad escondida en Cuernavaca: filtrados Chill, Groove y Funky, pan de masa madre y procesos manuales. Miércoles a lunes, 8:00–20:00.' => 'A specialty coffee bar hidden in Cuernavaca: Chill, Groove and Funky filter brews, sourdough bread and manual processes. Wednesday to Monday, 8:00–20:00.',
	'La carta de Haramara: cafés, salados y especiales con precios.' => 'The Haramara menu: coffees, savory dishes and specials, with prices.',
	'La carta de Haramara, Cuernavaca: espresso, cold brew, filtrados Chill/Groove/Funky, croissants, bagels, pretzel y especiales. Ordena para recoger.' => 'The Haramara menu, Cuernavaca: espresso, cold brew, Chill/Groove/Funky filters, croissants, bagels, pretzel and specials. Order for pickup.',
	'La historia de Haramara y su casa gastronómica.'      => 'The story of Haramara and its gastronomic house.',
	'Haramara nace de la casa gastronómica de Cocina Suiza, Pacífica y Malva: más de treinta años de oficio en Cuernavaca, ahora concentrados en café y masa madre.' => 'Haramara comes from the gastronomic house of Cocina Suiza, Pacífica and Malva: more than thirty years of craft in Cuernavaca, now focused on coffee and sourdough.',
	'Eventos del mes en la barra de Haramara.'             => 'This month’s events at the Haramara bar.',
	'Lo que pasa este mes en la barra de Haramara, Cuernavaca. Cupos limitados; aparta por WhatsApp.' => 'What is happening this month at the Haramara bar, Cuernavaca. Limited spots; reserve on WhatsApp.',
	'Lealtad Haramara: tu QR, tus sellos, tus canjes.'     => 'Haramara Loyalty: your QR, your stamps, your redemptions.',
	'Lealtad Haramara vive en un código QR: cada visita suma y los canjes se aplican en barra. Crea tu cuenta en un minuto.' => 'Haramara Loyalty lives in a QR code: every visit counts and rewards are applied at the bar. Create your account in one minute.',
	'Dónde está Haramara y cuándo abre.'                   => 'Where Haramara is, and when it opens.',
	'Haramara: Tulipán 302, esq. Hule, Col. Delicias, Cuernavaca. Miércoles a lunes de 8:00 a 20:00; martes descansamos. WhatsApp 777 136 2228.' => 'Haramara: Tulipán 302, corner of Hule, Col. Delicias, Cuernavaca. Wednesday to Monday, 8:00 to 20:00; on Tuesdays we rest. WhatsApp 777 136 2228.',

	/* --------------------------------------------------------- Alt text */
	'Barista de Haramara vertiendo leche sobre un café en la penumbra de la barra' => 'Haramara barista pouring milk into a coffee in the half-light of the bar',
	'Pieza de laminado con crema quemada al soplete, servida en plato de acero sobre mármol negro' => 'Laminated pastry with torched custard, served on a steel plate over black marble',
	'Interior de Haramara: banca de lino con cojines de barro, mesas de mármol negro y muro de piedra al fondo' => 'Inside Haramara: linen banquette with clay-toned cushions, black marble tables and a stone wall behind',
	'Botella de kombucha Orí a contraluz frente a una celosía de ladrillo, en la hora dorada' => 'Orí kombucha bottle backlit against a brick lattice at golden hour',
	'La celosía de ladrillo de Haramara a contraluz durante la hora dorada' => 'Haramara’s brick lattice backlit during the golden hour',
	'Servicio en Haramara: plato de acero con pretzel y encurtidos llevado a una mesa de mármol negro' => 'Service at Haramara: a steel plate with pretzel and pickles carried to a black marble table',
	'Bowl de pudding de chía con fruta y granola sobre la mesa de piedra negra' => 'Chia pudding bowl with fruit and granola on the black stone table',
	'Bowl de pudding de chía con mango, fresa, kiwi y granola sobre mesa de piedra negra.' => 'Chia pudding bowl with mango, strawberry, kiwi and granola on a black stone table.',
	'Sello de Haramara: una flama dorada dibujada a mano dentro de un anillo de latón' => 'Haramara seal: a hand-drawn golden flame inside a brass ring',

	/* ----------------------------------------------------- Page titles */
	'>Inicio<'                                             => '>Home<',
	'<title>La carta'                                      => '<title>The menu',
	'<title>Historia'                                      => '<title>Story',
	'<title>Eventos'                                       => '<title>Events',
	'<title>Lealtad'                                       => '<title>Loyalty',
	'<title>Visítanos'                                     => '<title>Visit us',
	'<title>Diario'                                        => '<title>Journal',
	'<title>Inicio'                                        => '<title>Home',
	'<title>Tu carrito'                                    => '<title>Your cart',
	'<title>Carrito'                                       => '<title>Cart',
	'<title>Mi cuenta'                                     => '<title>My account',
	'>Carrito<'                                            => '>Cart<',
	'>Mi cuenta<'                                          => '>My account<',
	'<title>Aviso de privacidad'                           => '<title>Privacy notice',
	'<title>Términos'                                      => '<title>Terms',

	/* ---------------------------------------------------------------------
	 * Hardcoded relative links in parts/patterns/templates. Generated
	 * permalinks are prefixed by the home_url filter; these literals are
	 * rewritten here so navigation stays inside the /en/ tree.
	 * ------------------------------------------------------------------ */
	'href="/carta"'                                        => 'href="/en/carta"',
	'href="/historia"'                                     => 'href="/en/historia"',
	'href="/eventos"'                                      => 'href="/en/eventos"',
	'href="/lealtad"'                                      => 'href="/en/lealtad"',
	'href="/mi-cuenta"'                                    => 'href="/en/mi-cuenta"',
	'href="/aviso-de-privacidad"'                          => 'href="/en/aviso-de-privacidad"',
	'href="/terminos"'                                     => 'href="/en/terminos"',
	'href="/#visitanos"'                                   => 'href="/en/#visitanos"',
	'href="/">'                                            => 'href="/en/">',
);
