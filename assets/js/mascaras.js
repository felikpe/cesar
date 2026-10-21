function aplicarMascara(input, padrao) {
    input.addEventListener('input', function () {
        const numeros = input.value.replace(/\D/g, '');
        let resultado = '';
        let posicao = 0;

        for (let i = 0; i < padrao.length && posicao < numeros.length; i++) {
            if (padrao[i] === '0') {
                resultado += numeros[posicao];
                posicao++;
            } else {
                resultado += padrao[i];
            }
        }

        input.value = resultado;
    });
}

aplicarMascara(document.getElementById('cpf'), '000.000.000-00');
aplicarMascara(document.getElementById('ra'), '00.000.000000-0');