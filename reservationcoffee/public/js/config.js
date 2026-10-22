/**
 * Reservation Coffee Break - Admin Config Page JS
 */
document.addEventListener('DOMContentLoaded', function () {
    const formSelect = document.getElementById('coffee_form_id');
    if (!formSelect) {
        return;
    }

    const ajaxUrl = formSelect.getAttribute('data-ajax-url');
    if (!ajaxUrl) {
        return;
    }

    const questionSelects = {
        reservation_question_id: { el: document.getElementById('reservation_question_id'), patterns: [/id.*reserva/i, /reservation.*id/i] },
        requester_question_id:   { el: document.getElementById('requester_question_id'), patterns: [/solicitante/i, /requester/i] },
        date_question_id:        { el: document.getElementById('date_question_id'), patterns: [/^data/i, /date/i] },
        time_question_id:        { el: document.getElementById('time_question_id'), patterns: [/hor[aá]rio/i, /time/i] },
        room_question_id:        { el: document.getElementById('room_question_id'), patterns: [/sala/i, /room/i] },
        participants_question_id:{ el: document.getElementById('participants_question_id'), patterns: [/participante/i] },
    };

    formSelect.addEventListener('change', function () {
        const formId = parseInt(this.value, 10);
        if (!formId || formId <= 0) {
            resetAllSelects();
            return;
        }

        fetch(`${ajaxUrl}?forms_forms_id=${formId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error ${response.status}`);
                }
                return response.json();
            })
            .then(questions => {
                updateSelectOptions(questions);
            })
            .catch(error => {
                console.error('Failed to load questions for form:', error);
            });
    });

    function resetAllSelects() {
        Object.values(questionSelects).forEach(({ el }) => {
            if (!el) return;
            const noneText = el.getAttribute('data-none-text') || '--';
            el.innerHTML = `<option value="">${noneText}</option>`;
        });
    }

    function updateSelectOptions(questions) {
        Object.entries(questionSelects).forEach(([key, { el, patterns }]) => {
            if (!el) return;
            const currentValue = el.value;
            const noneText = el.getAttribute('data-none-text') || '--';

            let optionsHtml = `<option value="">${noneText}</option>`;
            let matchedId = '';

            questions.forEach(q => {
                const isSelected = currentValue && String(q.id) === String(currentValue);
                if (isSelected) {
                    matchedId = String(q.id);
                }
                optionsHtml += `<option value="${q.id}" ${isSelected ? 'selected' : ''}>${escapeHtml(q.name)}</option>`;
            });

            el.innerHTML = optionsHtml;

            // If nothing was selected before, try auto-matching by pattern
            if (!matchedId && patterns) {
                for (const q of questions) {
                    if (patterns.some(p => p.test(q.name))) {
                        el.value = String(q.id);
                        break;
                    }
                }
            }
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
