<?php
// Modal pentru feedback după returnare - SE INCLUDE ÎN impartasiri.php
?>

<!-- Modal Feedback Ranking - Apare OPȚIONAL după returnare -->
<div id="modalFeedbackRanking" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
     background: rgba(0,0,0,0.6); z-index: 10000; align-items: center; justify-content: center;">
    
    <div style="background: white; padding: 30px; border-radius: 15px; max-width: 500px; width: 90%; 
                box-shadow: 0 10px 30px rgba(0,0,0,0.3); animation: slideIn 0.3s ease;">
        
        <div id="feedbackContent">
            <!-- Conținutul se va încărca dinamic -->
        </div>
        
    </div>
</div>

<script>
// Funcție pentru afișare feedback după returnare
function afiseazaFeedbackModal(idCerere, tipUtilizator) {
    const modal = document.getElementById('modalFeedbackRanking');
    const content = document.getElementById('feedbackContent');
    
    if (tipUtilizator === 'proprietar') {
        // FEEDBACK DE LA PROPRIETAR despre starea obiectului
        content.innerHTML = `
            <h3 style="color: #667eea; margin-bottom: 20px;">
                ✨ Cum a fost returnat obiectul?
            </h3>
            
            <p style="color: #666; margin-bottom: 20px;">
                Feedback-ul tău ajută la îmbunătățirea comunității (opțional)
            </p>
            
            <form id="formFeedbackProprietar" onsubmit="trimiteFeedback(event, ${idCerere}, 'proprietar')">
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600;">
                        Starea obiectului:
                    </label>
                    <select name="stare_obiect" style="width: 100%; padding: 10px; border: 1px solid #ddd; 
                            border-radius: 5px; font-size: 14px;">
                        <option value="">-- Alege (opțional) --</option>
                        <option value="perfecta">😊 Perfectă - ca nou</option>
                        <option value="buna">👍 Bună - stare normală</option>
                        <option value="uzura_normala">👌 Uzură normală de folosință</option>
                        <option value="deteriorat_usor">😕 Deteriorat ușor</option>
                        <option value="deteriorat_grav">😟 Deteriorat grav</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600;">
                        Observații (opțional):
                    </label>
                    <textarea name="observatii" rows="3" 
                              style="width: 100%; padding: 10px; border: 1px solid #ddd; 
                                     border-radius: 5px; font-size: 14px; resize: vertical;"
                              placeholder="Orice detalii relevante..."></textarea>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600;">
                        Rating general: ⭐
                    </label>
                    <div class="rating-stars" style="font-size: 30px; cursor: pointer;">
                        <span data-rating="1">☆</span>
                        <span data-rating="2">☆</span>
                        <span data-rating="3">☆</span>
                        <span data-rating="4">☆</span>
                        <span data-rating="5">☆</span>
                    </div>
                    <input type="hidden" name="rating" id="ratingValue" value="">
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="inchideFeedbackModal()" 
                            style="padding: 10px 20px; background: #e0e0e0; border: none; 
                                   border-radius: 5px; cursor: pointer;">
                        Mai târziu
                    </button>
                    <button type="submit" 
                            style="padding: 10px 20px; background: linear-gradient(135deg, #667eea, #764ba2); 
                                   color: white; border: none; border-radius: 5px; cursor: pointer;">
                        Trimite Feedback
                    </button>
                </div>
            </form>
        `;
        
    } else {
        // FEEDBACK DE LA ÎMPRUMUTĂTOR despre experiență
        content.innerHTML = `
            <h3 style="color: #667eea; margin-bottom: 20px;">
                ✨ Cum a fost experiența ta?
            </h3>
            
            <p style="color: #666; margin-bottom: 20px;">
                Feedback-ul tău ajută alți utilizatori (opțional)
            </p>
            
            <form id="formFeedbackImprumutator" onsubmit="trimiteFeedback(event, ${idCerere}, 'imprumutator')">
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600;">
                        Experiența generală:
                    </label>
                    <select name="experienta" style="width: 100%; padding: 10px; border: 1px solid #ddd; 
                            border-radius: 5px; font-size: 14px;">
                        <option value="">-- Alege (opțional) --</option>
                        <option value="excelenta">😊 Excelentă</option>
                        <option value="buna">👍 Bună</option>
                        <option value="satisfacatoare">👌 Satisfăcătoare</option>
                        <option value="slaba">😕 Slabă</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600;">
                        Comentarii (opțional):
                    </label>
                    <textarea name="observatii" rows="3" 
                              style="width: 100%; padding: 10px; border: 1px solid #ddd; 
                                     border-radius: 5px; font-size: 14px; resize: vertical;"
                              placeholder="Cum a fost comunicarea, predarea, etc..."></textarea>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600;">
                        Rating: ⭐
                    </label>
                    <div class="rating-stars" style="font-size: 30px; cursor: pointer;">
                        <span data-rating="1">☆</span>
                        <span data-rating="2">☆</span>
                        <span data-rating="3">☆</span>
                        <span data-rating="4">☆</span>
                        <span data-rating="5">☆</span>
                    </div>
                    <input type="hidden" name="rating" id="ratingValue" value="">
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="inchideFeedbackModal()" 
                            style="padding: 10px 20px; background: #e0e0e0; border: none; 
                                   border-radius: 5px; cursor: pointer;">
                        Mai târziu
                    </button>
                    <button type="submit" 
                            style="padding: 10px 20px; background: linear-gradient(135deg, #667eea, #764ba2); 
                                   color: white; border: none; border-radius: 5px; cursor: pointer;">
                        Trimite Feedback
                    </button>
                </div>
            </form>
        `;
    }
    
    // Inițializează stelele pentru rating
    initializeRatingStars();
    
    // Afișează modal
    modal.style.display = 'flex';
}

// Funcție pentru stelele de rating
function initializeRatingStars() {
    const stars = document.querySelectorAll('.rating-stars span');
    const ratingInput = document.getElementById('ratingValue');
    
    stars.forEach(star => {
        star.addEventListener('click', function() {
            const rating = this.getAttribute('data-rating');
            ratingInput.value = rating;
            
            // Actualizează vizual
            stars.forEach((s, index) => {
                if (index < rating) {
                    s.textContent = '★';
                    s.style.color = '#ffc107';
                } else {
                    s.textContent = '☆';
                    s.style.color = '#ddd';
                }
            });
        });
        
        star.addEventListener('mouseover', function() {
            const rating = this.getAttribute('data-rating');
            stars.forEach((s, index) => {
                if (index < rating) {
                    s.style.color = '#ffc107';
                } else {
                    s.style.color = '#ddd';
                }
            });
        });
    });
}

// Funcție pentru trimitere feedback
function trimiteFeedback(event, idCerere, tipUtilizator) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    formData.append('actiune', 'adauga_feedback');
    formData.append('id_cerere', idCerere);
    formData.append('tip_utilizator', tipUtilizator);
    
    fetch('ajax_ranking.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Afișează confirmare
            document.getElementById('feedbackContent').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div style="font-size: 60px; color: #4CAF50; margin-bottom: 20px;">✅</div>
                    <h3 style="color: #333; margin-bottom: 10px;">Mulțumim pentru feedback!</h3>
                    <p style="color: #666;">Contribuția ta ajută comunitatea.</p>
                </div>
            `;
            
            setTimeout(() => {
                inchideFeedbackModal();
                // Actualizează afișarea ranking-ului dacă este vizibil
                if (typeof actualizareRankingDisplay === 'function') {
                    actualizareRankingDisplay();
                }
            }, 2000);
        }
    });
}

// Funcție pentru închidere modal
function inchideFeedbackModal() {
    document.getElementById('modalFeedbackRanking').style.display = 'none';
}

// Detectează când se face returnare cu QR și afișează modal feedback
// Aceasta se va apela din ajax_imprumut.php după confirmaTransfer cu tip=returnare
function verificaAfisareFeedback(idCerere) {
    // Verifică dacă utilizatorul curent este proprietar sau împrumutător
    fetch(`ajax_imprumut.php?actiune=get_detalii_cerere&id_cerere=${idCerere}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const idUtilizatorCurent = <?php echo $_SESSION['user_data']['id_utilizator'] ?? 0; ?>;
                
                if (data.cerere.id_proprietar == idUtilizatorCurent) {
                    // Este proprietarul - întreabă despre starea obiectului
                    setTimeout(() => {
                        afiseazaFeedbackModal(idCerere, 'proprietar');
                    }, 1000);
                } else if (data.cerere.id_solicitant == idUtilizatorCurent) {
                    // Este împrumutătorul - întreabă despre experiență
                    setTimeout(() => {
                        afiseazaFeedbackModal(idCerere, 'imprumutator');
                    }, 1000);
                }
            }
        });
}
</script>

<style>
@keyframes slideIn {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.rating-stars span {
    transition: color 0.2s;
}

.rating-stars span:hover {
    transform: scale(1.1);
}
</style>