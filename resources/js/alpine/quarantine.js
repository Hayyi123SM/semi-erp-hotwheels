export function registerQuarantineCalculator(Alpine) {
    Alpine.data('quarantineCalc', () => ({
        selectedCase: null,
        selectedCandidate: 'a',
        system: 4,
        counted: 2,
        quarantine: 1,
        ambiguous: false,
        evidence: { missingLabel: false, damagedBlister: false, unidentified: false },

        get variance() {
            return this.system - (this.counted + this.quarantine);
        },

        get canAssign() {
            return this.variance >= 0 && !this.ambiguous && Object.values(this.evidence).some(Boolean);
        },

        get tone() {
            if (this.variance < 0) return 'error';
            if (this.variance > 0) return 'warning';
            return 'success';
        },

        resetCase(caseNumber) {
            this.system = 4;
            this.counted = 2;
            this.quarantine = 1;
            this.ambiguous = false;
            this.evidence = { missingLabel: false, damagedBlister: false, unidentified: false };
            window.Alpine.store('toast').push(`Kasus Q-2026-0${String(caseNumber).padStart(2, '0')} dimuat (mock)`, 'info');
        },

        assign() {
            if (!this.canAssign) {
                window.Alpine.store('toast').push('Lengkapi bukti verifikasi & pastikan selisih ≥ 0.', 'warning');
                return;
            }
            window.Alpine.store('toast').push(`Stok disesuaikan. Selisih: ${this.variance} unit.`, 'success');
        },

        escalate() {
            window.Alpine.store('toast').push('Kasus dieskalasi ke Owner. Notifikasi WA dikirim.', 'info');
        },
    }));
}