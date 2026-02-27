<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Adaugă Imagini Obiecte</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/style-telefon.css" media="only screen and (max-width: 768px)">
    <style>
        /* Stiluri inline */
        .upload-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-top: 15px;
            margin-bottom: 15px;
        }

        .upload-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            background-color: #4CAF50;
            color: white;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            position: relative;
        }

        .upload-button:hover {
            background-color: #45a049;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }

        .upload-button input[type="file"] {
            position: absolute;
            width: 0.1px;
            height: 0.1px;
            opacity: 0;
            overflow: hidden;
            z-index: -1;
        }

        #btn-submit {
            min-width: 50px;
            max-width: 160px;
            padding: 10px 12px;
            font-size: 14px;
            border-radius: 25px;
            background-color: #007BFF;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
        }

        #btn-submit:hover:not(:disabled) {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }

        #btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Stiluri pentru autocompletare */
        .autocomplete-container {
            position: relative;
            width: 100%;
        }

        .autocomplete-list {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            background-color: white;
            border: 1px solid #ddd;
            border-top: none;
            z-index: 99;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 0 0 4px 4px;
            display: none;
        }

        .autocomplete-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #f4f4f4;
        }

        .autocomplete-item:hover {
            background-color: #f0f0f0;
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        /* Stiluri pentru cutii - design cu dimensiuni reduse */
        .cutii-existente-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 8px;
            padding: 10px;
        }

        .cutie-existenta {
            position: relative;
            width: 100%;
            height: 60px;
            border: 2px solid #333;
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s;
            background-color: #f5f5f5;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Creăm aspectul tip plasă/grătar pe pereții cutiei */
        .cutie-existenta::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image:
                    repeating-linear-gradient(to right,
                    transparent,
                    transparent 6px,
                    #ccc 6px,
                    #ccc 8px),
                    repeating-linear-gradient(to bottom,
                    transparent,
                    transparent 6px,
                    #ccc 6px,
                    #ccc 8px);
            opacity: 0.7;
            z-index: 1;
        }

        /* Bara superioară a cutiei */
        .cutie-existenta::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 8px;
            background-color: #333;
        }

        .cutie-existenta:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        /* Eticheta pentru numărul cutiei - acum mult mai îngustă */
        .cutie-eticheta {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: white;
            color: #333;
            padding: 2px 6px;
            min-width: 40%;
            max-width: 60%;
            border: 1px solid #333;
            border-radius: 2px;
            font-size: 11px;
            font-weight: bold;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            text-align: center;
            z-index: 2;
        }

        .lista-titlu {
            padding: 8px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
            color: #555;
            text-align: center;
        }

        /* Stil pentru mesajul de succes */
        .mesaj-succes {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: none;
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Media queries pentru responsive design */
        @media screen and (max-width: 768px) {
            body {
                padding: 10px;
                padding-top: 70px; /* Spațiu pentru header fix */
            }
            
            .container {
                width: 100%;
                max-width: 100%;
                padding: 15px;
                margin: 0;
                box-shadow: none;
                border-radius: 0;
                margin-top: 20px; /* Spațiu suplimentar după header */
            }
            
            h2 {
                font-size: 22px;
                margin-bottom: 15px;
            }
            
            /* Formulare responsive */
            .form-group {
                margin-bottom: 15px;
            }
            
            label {
                font-size: 14px;
                margin-bottom: 8px;
            }
            
            input[type="text"],
            input[type="file"],
            select,
            textarea {
                font-size: 14px;
                padding: 10px;
            }
            
            /* Buton submit */
            input[type="submit"] {
                width: 100%;
                padding: 12px;
                font-size: 16px;
                margin-top: 10px;
            }
            
            /* Sugestii */
            .sugestii {
                font-size: 13px;
                padding: 10px;
                max-height: 120px;
            }
            
            .sugestie-item {
                padding: 8px;
                font-size: 13px;
            }
            
            /* Checkbox pentru colecții */
            .checkbox-container {
                margin: 10px 0;
            }
            
            input[type="checkbox"] {
                width: 18px;
                height: 18px;
                margin-right: 8px;
            }
            
            /* Preview imagini */
            .preview-container {
                flex-direction: column;
                gap: 10px;
            }
            
            .preview-image {
                max-width: 100%;
                height: auto;
            }
            
            /* Mesaje de eroare/succes */
            .error, .success {
                font-size: 14px;
                padding: 10px;
                margin: 10px 0;
            }
            
            /* Link înapoi */
            .back-link {
                font-size: 14px;
                padding: 8px;
            }
        }
        
        /* Pentru telefoane foarte mici (sub 480px) */
        @media screen and (max-width: 480px) {
            body {
                padding: 5px;
                padding-top: 65px; /* Spațiu pentru header fix pe ecrane mici */
                font-size: 14px;
            }
            
            .container {
                padding: 10px;
                margin-top: 15px; /* Spațiu suplimentar după header */
            }
            
            h2 {
                font-size: 18px;
                margin-bottom: 12px;
            }
            
            /* Form groups mai compacte */
            .form-group {
                margin-bottom: 12px;
            }
            
            label {
                font-size: 13px;
                margin-bottom: 6px;
                font-weight: 600;
            }
            
            /* Toate input-urile */
            input[type="text"],
            input[type="file"],
            select,
            textarea {
                font-size: 13px;
                padding: 8px;
                border-radius: 4px;
            }
            
            /* File input special styling pentru mobile */
            input[type="file"] {
                font-size: 12px;
                padding: 10px 8px;
            }
            
            /* Textarea mai mic */
            textarea {
                min-height: 60px;
                resize: vertical;
            }
            
            /* Select dropdown */
            select {
                background-size: 20px;
                padding-right: 30px;
            }
            
            /* Submit button */
            input[type="submit"] {
                width: 100%;
                padding: 10px;
                font-size: 14px;
                font-weight: 600;
                border-radius: 5px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                margin-top: 15px;
            }
            
            input[type="submit"]:active {
                transform: scale(0.98);
            }
            
            /* Sugestii mai compacte */
            .sugestii {
                font-size: 12px;
                padding: 8px;
                max-height: 100px;
                border-radius: 4px;
            }
            
            .sugestie-item {
                padding: 6px;
                font-size: 12px;
                border-radius: 3px;
            }
            
            .sugestie-item:active {
                background-color: #e0e0e0;
            }
            
            /* Checkbox container */
            .checkbox-container {
                margin: 8px 0;
                display: flex;
                align-items: center;
            }
            
            input[type="checkbox"] {
                width: 16px;
                height: 16px;
                margin-right: 6px;
            }
            
            .checkbox-label {
                font-size: 13px;
            }
            
            /* Helper text */
            .helper-text,
            .info-text {
                font-size: 11px;
                color: #666;
                margin-top: 5px;
            }
            
            /* Mesaje */
            .error, .success {
                font-size: 13px;
                padding: 8px;
                margin: 8px 0;
                border-radius: 4px;
            }
            
            /* Back link mai mic */
            .back-link {
                font-size: 13px;
                padding: 6px;
                display: inline-block;
                margin-bottom: 10px;
            }
            
            /* Focus states pentru touch */
            input:focus,
            select:focus,
            textarea:focus {
                outline: 2px solid #667eea;
                outline-offset: 2px;
            }
            
            /* Spacing între secțiuni */
            .section {
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 1px solid #eee;
            }
            
            .section:last-child {
                border-bottom: none;
            }
            
            /* Icons în labels */
            .label-icon {
                font-size: 16px;
                margin-right: 4px;
            }
            
            /* Required asterisk */
            .required {
                color: #ff6600;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
<nav class="navbar">

    <a href="index.php" class="modern-link">📋 Listă Obiecte</a>
</nav>

<div class="container">
    <?php if (isset($_GET['succes']) && $_GET['succes'] == 1): ?>
        <div class="mesaj-succes" style="display: block;">
            ✅ Imaginile au fost adăugate cu succes! Poți adăuga altele.
        </div>
    <?php endif; ?>

    <!-- Mesajul de succes dinamic (va fi afișat prin JavaScript) -->
    <div id="mesaj-succes-dinamic" class="mesaj-succes">
        ✅ Imaginile au fost adăugate cu succes! Poți adăuga altele.
    </div>

    <h1>Adaugă imagini obiecte</h1>

    <form action="adauga_obiect.php" method="POST" enctype="multipart/form-data" id="formular-upload">
        <!-- Întâi locația, apoi cutia -->
        <div class="autocomplete-container">
            <label for="locatie">Locație:</label>
            <input type="text" name="locatie" id="locatie" placeholder="Atelier - camera din spate" required autocomplete="off">
            <div id="locatie-autocomplete" class="autocomplete-list"></div>
        </div>

        <div class="autocomplete-container">
            <label for="cutie">Cutie:</label>
            <input type="text" name="cutie" id="cutie" placeholder="cutie #1" required autocomplete="off">
            <div id="cutie-autocomplete" class="autocomplete-list"></div>
        </div>

        <!-- Valori implicite ascunse pentru categorie și descriere categorie -->
        <input type="hidden" name="categorie" value="obiecte">
        <input type="hidden" name="descriere_categorie" value="obiecte">
        <?php
        session_start();
        // Prioritate: GET > sesiune upload > sesiune selectată > sesiune curentă
        $id_colectie = $_GET['colectie'] ?? $_SESSION['upload_colectie_id'] ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? '';
        // IMPORTANT: Salvăm ID-ul colecției în TOATE sesiunile pentru a persista între request-uri
        if ($id_colectie) {
            $_SESSION['id_colectie_curenta'] = $id_colectie;
            $_SESSION['id_colectie_selectata'] = $id_colectie;
            $_SESSION['upload_colectie_id'] = $id_colectie; // Sesiune specifică pentru upload
            error_log("adauga_imagini.php - Salvez în toate sesiunile: id_colectie = $id_colectie");
        }
        ?>
        <input type="hidden" name="id_colectie" id="id_colectie" value="<?php echo $id_colectie; ?>">

        <label>Imagini obiecte:</label>

        <!-- Container pentru toate cele 3 butoane -->
        <div class="upload-controls">
            <!-- Buton pentru galerie -->
            <label for="imagini-galerie" class="upload-button" title="Alege din galerie">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2z"></path>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
                <input type="file" name="imagini[]" id="imagini-galerie" accept="image/*" multiple>
            </label>

            <!-- Buton de submit centrat - text scurtat -->
            <button type="submit" id="btn-submit" disabled>Adaugă</button>

            <!-- Buton pentru cameră -->
            <label for="imagini-camera" class="upload-button" title="Fotografiază acum">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                    <circle cx="12" cy="13" r="4"></circle>
                </svg>
                <input type="file" name="imagini[]" id="imagini-camera" accept="image/*" capture="environment">
            </label>
        </div>

        <div id="preview-thumbnails" style="margin-top:15px; display:flex; flex-wrap:wrap; gap:10px;"></div>
    </form>
</div>

<script>
    // Inițializare elemente DOM
    const inputGalerie = document.getElementById('imagini-galerie');
    const inputCamera = document.getElementById('imagini-camera');
    const previewContainer = document.getElementById('preview-thumbnails');
    const formular = document.getElementById('formular-upload');
    const submitBtn = document.getElementById('btn-submit');
    const inputCutie = document.getElementById('cutie');
    const inputLocatie = document.getElementById('locatie');
    const cutieAutocomplete = document.getElementById('cutie-autocomplete');
    const locatieAutocomplete = document.getElementById('locatie-autocomplete');
    const mesajSuccesDinamic = document.getElementById('mesaj-succes-dinamic');

    // Variabile pentru starea aplicației
    let imaginiSelectate = [];
    let locatieSelectata = '';
    let cutiiExistente = []; // Pentru a stoca cutiile existente pentru locația curentă

    // Dezactivăm butonul de submit inițial
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.5';

    // Funcție pentru a afișa mesajul de succes și a reseta formularul
    function afiseazaMesajSucces() {
        // Afișăm mesajul de succes
        mesajSuccesDinamic.style.display = 'block';

        // Facem scroll la începutul paginii pentru a vedea mesajul
        window.scrollTo({ top: 0, behavior: 'smooth' });

        // Ascundem mesajul după 5 secunde
        setTimeout(() => {
            mesajSuccesDinamic.style.display = 'none';
        }, 5000);

        // Resetăm formularul
        resetareFormular();
    }

    // Funcție pentru a reseta complet formularul
    function resetareFormular() {
        // Resetăm câmpurile de text
        inputLocatie.value = '';
        inputCutie.value = '';

        // Resetăm locația selectată
        locatieSelectata = '';

        // Resetăm listele de sugestii
        cutiiExistente = [];
        cutieAutocomplete.innerHTML = '';
        cutieAutocomplete.style.display = 'none';
        locatieAutocomplete.innerHTML = '';
        locatieAutocomplete.style.display = 'none';

        // Resetăm lista de imagini
        imaginiSelectate = [];
        previewContainer.innerHTML = '';

        // Resetăm butonul de submit
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
        submitBtn.textContent = 'Adaugă';

        // Resetăm câmpurile de input file
        inputGalerie.value = '';
        inputCamera.value = '';
    }

    // Funcție îmbunătățită pentru a obține sugestii de la server
    function obtineSugestii(tip, cautare = '', locatie = '') {
        // Construim URL-ul pentru cerere
        let url = `obtine_sugestii.php?tip=${tip}`;

        if (cautare !== null) {
            url += `&cautare=${encodeURIComponent(cautare)}`;
        }

        if (locatie && tip === 'cutie') {
            url += `&locatie=${encodeURIComponent(locatie)}`;
        }

        // Adăugăm ID-ul colecției dacă există
        const idColectieElement = document.getElementById('id_colectie');
        if (idColectieElement && idColectieElement.value) {
            url += `&id_colectie=${idColectieElement.value}`;
        }

        // Adăugăm parametru pentru prevenirea cache-ului
        url += `&timestamp=${new Date().getTime()}`;

        console.log(`Cerere sugestii: ${url}`);

        // Efectuăm cererea HTTP
        return fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Eroare HTTP! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log(`Sugestii primite pentru ${tip}:`, data);

                // Dacă primim cutii, actualizăm variabila globală
                if (tip === 'cutie') {
                    cutiiExistente = data;
                }

                return data;
            })
            .catch(error => {
                console.error(`Eroare la obținerea sugestiilor pentru ${tip}:`, error);
                return [];
            });
    }

    // Funcție pentru a afișa sugestiile
    function afiseazaSugestii(lista, sugestii, input) {
        // Golim lista
        lista.innerHTML = '';

        // Verificăm dacă avem sugestii
        if (!sugestii || sugestii.length === 0) {
            lista.style.display = 'none';
            return;
        }

        // Adăugăm fiecare sugestie în listă
        sugestii.forEach(sugestie => {
            const item = document.createElement('div');
            item.className = 'autocomplete-item';
            item.textContent = sugestie;

            // Adăugăm event listener pentru click
            item.addEventListener('click', () => {
                input.value = sugestie;
                lista.style.display = 'none';

                // Dacă este selectată o locație, actualizăm variabila globală
                if (input === inputLocatie) {
                    locatieSelectata = sugestie;
                    console.log(`Locație selectată: ${locatieSelectata}`);

                    // Resetăm câmpul cutie
                    inputCutie.value = '';

                    // Resetăm lista de cutii existente
                    cutiiExistente = [];

                    // Preîncărcăm sugestiile pentru cutii pentru această locație
                    preincarcaCutiiPentruLocatie(locatieSelectata);
                }
            });

            lista.appendChild(item);
        });

        // Afișăm lista
        lista.style.display = 'block';
    }

    // Funcție pentru a afișa toate cutiile existente într-un format grid
    function afiseazaCutiiExistente(lista, cutii, input) {
        // Golim lista
        lista.innerHTML = '';

        // Verificăm dacă avem cutii existente
        if (!cutii || cutii.length === 0) {
            // Afișăm un mesaj informativ
            const mesajInfo = document.createElement('div');
            mesajInfo.className = 'lista-titlu';
            mesajInfo.textContent = 'Nu există cutii pentru această locație. Adaugă una nouă.';
            lista.appendChild(mesajInfo);
            lista.style.display = 'block';
            return;
        }

        // Adăugăm un titlu
        const titlu = document.createElement('div');
        titlu.className = 'lista-titlu';
        titlu.textContent = `${cutii.length} cutii existente pentru această locație:`;
        lista.appendChild(titlu);

        // Creăm un container grid pentru cutii
        const gridContainer = document.createElement('div');
        gridContainer.className = 'cutii-existente-grid';

        // Adăugăm fiecare cutie în grid
        cutii.forEach(cutie => {
            const item = document.createElement('div');
            item.className = 'cutie-existenta';

            // Creăm eticheta pentru numărul cutiei
            const eticheta = document.createElement('div');
            eticheta.className = 'cutie-eticheta';
            eticheta.textContent = cutie;

            // Adăugăm eticheta în cutie
            item.appendChild(eticheta);

            // Adăugăm event listener pentru click
            item.addEventListener('click', () => {
                input.value = cutie;
                lista.style.display = 'none';
            });

            gridContainer.appendChild(item);
        });

        lista.appendChild(gridContainer);

        // Afișăm lista
        lista.style.display = 'block';
    }

    // Funcție pentru a preîncărca cutiile pentru o locație
    function preincarcaCutiiPentruLocatie(locatie) {
        if (!locatie) return;

        console.log(`Preîncărcare cutii pentru locația: ${locatie}`);

        // Resetăm lista de cutii existente înainte de a încărca cele noi
        cutiiExistente = [];

        // Obține lista de cutii pentru această locație - pasăm null în loc de string gol
        // pentru a indica că vrem TOATE cutiile, nu doar cele care încep cu un anumit șir
        obtineSugestii('cutie', null, locatie)
            .then(sugestii => {
                console.log(`${sugestii.length} cutii preîncărcate pentru locația ${locatie}`);

                // Dacă utilizatorul are focus pe câmpul cutie, afișăm cutiile în format grid
                if (document.activeElement === inputCutie) {
                    afiseazaCutiiExistente(cutieAutocomplete, sugestii, inputCutie);
                }
            });
    }

    // Funcție îmbunătățită pentru a actualiza sugestiile de cutii în funcție de locație
    function actualizeazaSugestiiCutii(cautare) {
        // Verificăm dacă avem o locație selectată
        if (!locatieSelectata) {
            // Dacă nu avem locație selectată, dar există text în câmpul locație
            if (inputLocatie.value.trim()) {
                locatieSelectata = inputLocatie.value.trim();
                console.log(`Folosim locația din câmp: ${locatieSelectata}`);
            } else {
                console.log('Nu există locație selectată pentru a filtra cutiile');
                return;
            }
        }

        // Dacă nu există text de căutare, afișăm toate cutiile existente
        if (!cautare || cautare.length === 0) {
            if (cutiiExistente.length > 0) {
                // Folosim cutiile deja încărcate
                afiseazaCutiiExistente(cutieAutocomplete, cutiiExistente, inputCutie);
            } else {
                // Încărcăm toate cutiile pentru această locație
                preincarcaCutiiPentruLocatie(locatieSelectata);
            }
            return;
        }

        // Dacă există text de căutare, facem o căutare normală
        obtineSugestii('cutie', cautare, locatieSelectata)
            .then(sugestii => {
                // Dacă sunt afișate sugestiile (adică input-ul este în focus)
                if (document.activeElement === inputCutie) {
                    afiseazaSugestii(cutieAutocomplete, sugestii, inputCutie);
                }
            });
    }

    // Event listener pentru focus pe inputLocatie - NOU
    inputLocatie.addEventListener('focus', function() {
        // Resetăm placeholder-ul
        this.placeholder = '';

        // Încărcăm toate locațiile disponibile
        obtineSugestii('locatie', null)
            .then(sugestii => {
                // Afișăm toate sugestiile de locații
                afiseazaSugestii(locatieAutocomplete, sugestii, inputLocatie);
            });
    });

    // Event listener pentru input locație - MODIFICAT
    inputLocatie.addEventListener('input', function() {
        const cautare = this.value.trim();

        // Dacă nu există text, afișăm toate locațiile
        if (cautare.length === 0) {
            obtineSugestii('locatie', null)
                .then(sugestii => {
                    afiseazaSugestii(locatieAutocomplete, sugestii, inputLocatie);
                });
            return;
        }

        // Filtrăm locațiile după textul introdus
        obtineSugestii('locatie', cautare)
            .then(sugestii => {
                afiseazaSugestii(locatieAutocomplete, sugestii, inputLocatie);
            });
    });

    // Event listener pentru input cutie
    inputCutie.addEventListener('input', function() {
        const cautare = this.value.trim();

        if (cautare.length < 1) {
            // Când se șterge tot textul, afișăm din nou toate cutiile existente
            if (cutiiExistente.length > 0) {
                afiseazaCutiiExistente(cutieAutocomplete, cutiiExistente, inputCutie);
            } else {
                actualizeazaSugestiiCutii('');
            }
            return;
        }

        // Verificăm dacă avem o locație selectată
        if (!locatieSelectata && inputLocatie.value.trim()) {
            locatieSelectata = inputLocatie.value.trim();
        }

        // Actualizăm sugestiile pentru cutie
        actualizeazaSugestiiCutii(cautare);
    });

    // Când se face focus pe inputCutie, afișăm toate cutiile existente imediat
    inputCutie.addEventListener('focus', function() {
        // Verificăm dacă avem o locație selectată
        if (!locatieSelectata && inputLocatie.value.trim()) {
            locatieSelectata = inputLocatie.value.trim();
            console.log(`Locație setată la focus pe cutie: ${locatieSelectata}`);
        }

        // Resetăm placeholder-ul
        this.placeholder = '';

        // Dacă avem locație selectată, afișăm toate cutiile disponibile pentru acea locație
        if (locatieSelectata) {
            if (cutiiExistente.length > 0) {
                // Folosim cutiile deja încărcate
                afiseazaCutiiExistente(cutieAutocomplete, cutiiExistente, inputCutie);
            } else {
                // Încărcăm toate cutiile pentru această locație
                preincarcaCutiiPentruLocatie(locatieSelectata);
            }
        }
    });

    // Event listener pentru finalizarea selecției locației - MODIFICAT
    inputLocatie.addEventListener('change', function() {
        const nouaLocatie = this.value.trim();

        // Verificăm dacă locația s-a schimbat cu adevărat
        if (nouaLocatie !== locatieSelectata) {
            console.log(`Locație schimbată: de la "${locatieSelectata}" la "${nouaLocatie}"`);

            // Actualizăm variabila globală
            locatieSelectata = nouaLocatie;

            // Resetăm câmpul cutie
            inputCutie.value = '';

            // Resetăm lista de cutii existente
            cutiiExistente = [];
            cutieAutocomplete.innerHTML = '';
            cutieAutocomplete.style.display = 'none';

            // Preîncărcăm cutiile pentru această locație
            if (locatieSelectata) {
                preincarcaCutiiPentruLocatie(locatieSelectata);
            }
        }
    });

    // Ascunde listele de sugestii la click în afara lor
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.autocomplete-container')) {
            cutieAutocomplete.style.display = 'none';
            locatieAutocomplete.style.display = 'none';
        }
    });

    // Funcție pentru a afișa previzualizările imaginilor
    function afiseazaPrevizualizari(files) {
        Array.from(files).forEach(file => {
            // Verificăm dacă imaginea nu există deja în listă
            const fileExists = imaginiSelectate.some(img =>
                img.name === file.name &&
                img.size === file.size &&
                img.lastModified === file.lastModified
            );

            if (!fileExists) {
                imaginiSelectate.push(file);

                const reader = new FileReader();
                reader.onload = function(e) {
                    const divContainer = document.createElement('div');
                    divContainer.style.position = 'relative';

                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.width = '80px';
                    img.style.height = '80px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '8px';

                    const deleteBtn = document.createElement('button');
                    deleteBtn.innerHTML = '&times;';
                    deleteBtn.style.position = 'absolute';
                    deleteBtn.style.top = '-8px';
                    deleteBtn.style.right = '-8px';
                    deleteBtn.style.background = 'red';
                    deleteBtn.style.color = 'white';
                    deleteBtn.style.border = 'none';
                    deleteBtn.style.borderRadius = '50%';
                    deleteBtn.style.width = '20px';
                    deleteBtn.style.height = '20px';
                    deleteBtn.style.cursor = 'pointer';
                    deleteBtn.style.fontSize = '12px';
                    deleteBtn.style.lineHeight = '1';
                    deleteBtn.style.padding = '0';

                    deleteBtn.addEventListener('click', function(evt) {
                        evt.preventDefault();
                        const index = imaginiSelectate.indexOf(file);
                        if (index > -1) {
                            imaginiSelectate.splice(index, 1);
                        }
                        divContainer.remove();
                        actualizeazaButonSubmit();
                    });

                    divContainer.appendChild(img);
                    divContainer.appendChild(deleteBtn);
                    previewContainer.appendChild(divContainer);
                };
                reader.readAsDataURL(file);
            }
        });

        actualizeazaButonSubmit();
    }

    // Funcție pentru a actualiza starea butonului de submit
    function actualizeazaButonSubmit() {
        if (imaginiSelectate.length > 0) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.textContent = `Adaugă (${imaginiSelectate.length})`;
        } else {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            submitBtn.textContent = 'Adaugă';
        }
    }

    // Handler pentru selecția din galerie
    inputGalerie.addEventListener('change', function() {
        console.log("Galerie: fișiere selectate", this.files.length);
        afiseazaPrevizualizari(this.files);
    });

    // Handler pentru captura cu camera
    inputCamera.addEventListener('change', function() {
        console.log("Cameră: fișiere capturate", this.files.length);
        afiseazaPrevizualizari(this.files);
    });

    // Modificare pentru a trimite doar imaginile selectate la submit - MODIFICAT
    formular.addEventListener('submit', function(e) {
        e.preventDefault();

        if (imaginiSelectate.length === 0) {
            alert('Te rugăm să selectezi cel puțin o imagine.');
            return;
        }

        // Creăm un nou obiect FormData pentru a construi datele de trimitere
        const formData = new FormData();

        // Adăugăm câmpurile de text
        formData.append('cutie', inputCutie.value);
        formData.append('locatie', inputLocatie.value);
        formData.append('categorie', 'obiecte');
        formData.append('descriere_categorie', 'descrie obiecte');
        formData.append('eticheta', '#4CAF50'); // Verde pentru categoria implicită 'obiecte'

        // Adăugăm id_colectie dacă există
        const idColectie = document.getElementById('id_colectie');
        if (idColectie && idColectie.value) {
            formData.append('id_colectie', idColectie.value);
        }

        // Adăugăm imaginile selectate
        imaginiSelectate.forEach(file => {
            formData.append('imagini[]', file);
        });

        // Dezactivăm butonul pentru a preveni submituri multiple
        submitBtn.disabled = true;
        submitBtn.textContent = 'Se încarcă...';

        // Trimitem datele prin fetch
        fetch('adauga_obiect.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Eroare HTTP! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Salvăm informațiile în localStorage pentru scroll automat
                    localStorage.setItem('ultimulIdObiectVizualizat', data.id_obiect);
                    localStorage.setItem('ultimulNumeImagineVizualizat', data.imagine);

                    // Redirecționăm către index.php cu parametrii necesari
                    window.location.href = `index.php?id_obiect=${data.id_obiect}&imagine=${encodeURIComponent(data.imagine)}`;
                } else {
                    throw new Error('Răspuns invalid de la server');
                }
            })
            .catch(error => {
                console.error('Eroare:', error);
                alert('A apărut o eroare la încărcarea imaginilor. Te rugăm să încerci din nou.');
                // Re-activăm butonul
                submitBtn.disabled = false;
                submitBtn.textContent = `Adaugă (${imaginiSelectate.length})`;
            });
    });

    // Inițializăm lista de locații la încărcare
    window.addEventListener('load', function() {
        // Încărcăm toate locațiile existente
        obtineSugestii('locatie', '')
            .then(sugestii => {
                console.log("Locații preîncărcate:", sugestii.length);
            });
    });
</script>

</body>
</html>