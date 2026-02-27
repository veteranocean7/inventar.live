# Inventar.live - Sistem de Management al Inventarului

## Prezentare Generală
Inventar.live este o aplicație web pentru gestionarea inventarului de obiecte, cu funcționalități avansate de detectare automată folosind Google Vision AI.

## Funcționalități Principale

### 1. 📦 Gestionare Obiecte
- Organizare pe cutii și locații
- Adăugare manuală de obiecte cu cantități
- Editare inline a informațiilor
- Suport pentru imagini multiple per obiect

### 2. 🤖 Detectare Automată cu Google Vision
- Identificare automată a obiectelor din imagini
- Traducere automată din engleză în română
- Marcare vizuală distinctă (portocaliu) pentru obiectele detectate automat
- Tracking complet al sursei fiecărui obiect (manual/automat)

### 3. ✂️ Decupare Inteligentă
- Decupare manuală a obiectelor din imagini
- Salvare automată a imaginilor decupate
- Asociere automată cu obiectele din inventar

### 4. 🏷️ Sistem de Categorii și Etichete
- Categorii cu culori personalizate
- Etichete multiple per obiect
- Filtrare și căutare avansată

## Structura Bazei de Date

### Tabela `obiecte`
- `id_obiect` - identificator unic
- `denumire_obiect` - listă de obiecte cu format "Nume (index)"
- `cantitate_obiect` - cantități corespunzătoare
- `cutie` - locația cutiei
- `locatie` - locația fizică
- `categorie` - categorii asociate
- `eticheta` - etichete descriptive
- `imagine` - imagini asociate

### Tabela `detectii_obiecte`
- Tracking pentru sursa fiecărui obiect
- `sursa`: 'manual' sau 'google_vision'
- Permite raportare și analiză

## Tehnologii Utilizate
- **Backend**: PHP 7.4+
- **Bază de date**: MySQL/MariaDB
- **Frontend**: JavaScript vanilla, HTML5, CSS3
- **API extern**: Google Cloud Vision API
- **Biblioteci**: Cropper.js pentru decupare imagini

## Instalare și Configurare
Vezi [SETUP.md](SETUP.md) pentru instrucțiuni detaliate de instalare.

## Utilizare
Vezi [USAGE.md](USAGE.md) pentru ghid de utilizare.

## Licență
Acest proiect este proprietatea ID4K și nu poate fi redistribuit fără permisiune.

---
*Dezvoltat de ID4K - 2025*