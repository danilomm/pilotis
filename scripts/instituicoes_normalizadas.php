<?php
/**
 * Mapeamento de normalização de instituições
 * Chave = valor original (lowercase, trimmed)
 * Valor = nome normalizado
 */
return [
    // USP e unidades
    'usp' => 'USP',
    'universidade de são paulo' => 'USP',

    // FAU-USP (São Paulo) - também chamada FAUUSP ou FAU USP
    'fau usp' => 'FAU-USP',
    'fauusp' => 'FAU-USP',
    'fau-usp' => 'FAU-USP',
    'fau usp - universidade de são paulo' => 'FAU-USP',
    'fau- usp' => 'FAU-USP',
    'fau usp/sp' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo da universidade de são paulo' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo / universidade de são paulo' => 'FAU-USP',
    'universidade de são paulo, faculdade de arquitetura e urbanismo' => 'FAU-USP',
    'gmaa / fau usp (aposentado)' => 'FAU-USP',
    'metropole arquitetos/universidade presbiteriana mackenzie /fauusp' => 'Mackenzie / FAU-USP',
    'professora associada da faculdade de arquitetura e urbanismo da universidade de são paulo - fauusp' => 'FAU-USP',
    'gomes machado arquitetos associados / universidade de sao paulo - faculdade de arquitetura e urbanismo (aposentado)' => 'FAU-USP',
    'uefs (professor visitante), fau usp (pós-doutorado)' => 'UEFS / FAU-USP',
    'universidade de são paulo - fau usp' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo universidade de sao paulo' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo e de design da universidade de são paulo' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo e de design da universidade de são paulo (fau-usp)' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo e de design da universidade de são paulo (fau/usp)' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo e de design da universidade de são paulo (fau usp)' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo e de design da universidade de são paulo - fauusp' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo e de design da universidade de são paulo - fau usp' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo e de design da universidade de são paulo (fauusp)' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo e de design da usp (fau-usp)' => 'FAU-USP',
    'faculdade de arquitetura, urbanismo e design da universidade de são paulo' => 'FAU-USP',
    'faculdade de arquitetura, urbanismo e design da universidade de são paulo - fau usp' => 'FAU-USP',
    'fau usp - faculdade de arquitetura e urbanismo da universidade de são paulo' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo da universidade de são paulo (fauusp)' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo da universidade de são paulo fau usp' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo, universidade de são paulo - fau usp' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo da universidade de são paulo – fauusp' => 'FAU-USP',
    'universidade de são paulo  faculdade de arquitetura e urbanismo e design' => 'FAU-USP',
    'universidade de são paulo (fauusp)' => 'FAU-USP',
    'faculdade de arquiteura e arquitetura e design da universidade de são paulo' => 'FAU-USP',
    'professora faculdade de arquitetura e urbanismo da universidade de são paulo' => 'FAU-USP',
    'professor associado iii do departamento de projeto da faculdade de arquitetura e urbanismo da universidade de são paulo' => 'FAU-USP',
    'aposentada faculdade de arquitetura ufrgs' => 'FA-UFRGS',
    'récem doutorada pela fau usp' => 'FAU-USP',
    'pós- doc fauusp (em curso)' => 'FAU-USP',
    'fau usp e fau unb' => 'FAU-USP / FAU-UnB',
    'prourb-ufrj, fauusp' => 'PROURB-UFRJ / FAU-USP',
    'ppgau fau-usp (programa de pós-graduação em arquitetura e urbanismo, faculdade de arquitetura e urbanismo e de design da universidade de sâo paulo)' => 'FAU-USP',
    'pós- doc fauusp (em curso)' => 'FAU-USP',
    'faculdade de arquitetura e urbanismo - universidade de são paulo (fauusp)' => 'FAU-USP',
    'pós-doc fauusp (em curso)' => 'FAU-USP',

    // IAU-USP (São Carlos) - Instituto de Arquitetura e Urbanismo
    'iau-usp' => 'IAU-USP',
    'iau usp' => 'IAU-USP',
    'iau-usp-sc' => 'IAU-USP',
    'iau.usp' => 'IAU-USP',
    'pós-doutoranda no instituto de arquitetura e urbanismo, universidade de são paulo' => 'IAU-USP',
    'pós-doutoranda no instituto de arquitetura e urbanismo da universidade de são paulo' => 'IAU-USP',
    'universidade de são paulo, instituto de arquitetura e urbanismo (iau-usp)' => 'IAU-USP',
    'instituto de arquitetura e urbanismo da universidade de são paulo (iau usp)' => 'IAU-USP',
    'instituto de arquitetura e urbanismo da universidade de são paulo' => 'IAU-USP',
    'instituto de arquitetura e urbanismo da universidade de são paulo (iau-usp)' => 'IAU-USP',
    'instituto de arquitetura e urbanismo universidade de são paulo' => 'IAU-USP',
    'instituto de arquitetura e urbanismo - universidade de são paulo' => 'IAU-USP',
    'instituto de arquitetura e urbanismo usp são carlos' => 'IAU-USP',
    'instituto de arquitetura e urbanismo - iau usp' => 'IAU-USP',
    'iau usp - universidade de são paulo' => 'IAU-USP',
    'instituto arquitetura e urbanismo da universidade de são paulo – iau-usp-sc e faculdade arquitetura e urbanismo da universidade presbiteriana mackenzie' => 'IAU-USP / Mackenzie',
    'usjt/iau.usp' => 'Universidade São Judas Tadeu / IAU-USP',

    // UFRJ e unidades
    'ufrj' => 'UFRJ',
    'universidade federal do rio de janeiro (ufrj)' => 'UFRJ',
    'universidade federal do rio de janeiro' => 'UFRJ',

    // FAU-UFRJ (Faculdade de Arquitetura e Urbanismo)
    'fau ufrj' => 'FAU-UFRJ',
    'fau-ufrj' => 'FAU-UFRJ',
    'fau|ufrj' => 'FAU-UFRJ',
    'fau-ufrj universidade federal do rio de janeiro' => 'FAU-UFRJ',
    'faculdade de arquitetura e urbanismo - universidade federal do rio de janeiro' => 'FAU-UFRJ',
    'faculdade de arquitetura e urbanismo - universidade federal do rio de janeiro (fau ufrj)' => 'FAU-UFRJ',
    'faculdade de arquitetura e urbanismo, universidade federal do rio de janeiro' => 'FAU-UFRJ',
    'faculdade de arquitetura e urbanismo universidade federal do rio de janeiro' => 'FAU-UFRJ',
    'faculdade de arquitetura e urbanismo_fauufrj' => 'FAU-UFRJ',
    'faculdade de arquitetura e urbanismo da universidade federal do rio de janeiro' => 'FAU-UFRJ',
    'faculdade de arquitetura e urbanismo da universidade federal do rio de janeiro (fau-ufrj)' => 'FAU-UFRJ',
    'faculdade de arquitetura e urbanismo - universidade federal do rio de janeiro (fau ufrj)' => 'FAU-UFRJ',
    'faculdade de arquitetura e urbanismo e design - universidade federal de uberlândia' => 'FAUeD-UFU',
    'universidade federal do rio de janeiro (ufrj), faculdade de arquitetura e urbanismo (fau)' => 'FAU-UFRJ',
    'ufrj/fau/prourb' => 'PROURB-UFRJ',
    'ufrj fau prourb' => 'PROURB-UFRJ',
    'universidade federal do rio de janeiro / fau / prourb' => 'PROURB-UFRJ',
    'fau ufrj; ppgau-uff' => 'FAU-UFRJ / UFF',
    'professor da universidade federal do rio de janeiro' => 'UFRJ',
    'universidade federal do rio de janeiro - ufrj' => 'UFRJ',

    // PROARQ-UFRJ (Programa de Pós-Graduação em Arquitetura)
    'proarq-ufrj' => 'PROARQ-UFRJ',
    'proarq - fau - ufrj' => 'PROARQ-UFRJ',
    'proarq/ufrj' => 'PROARQ-UFRJ',
    'autônoma / doutoranda proarq-ufrj' => 'PROARQ-UFRJ',
    'programa de pós-graduação em arquitetura - universidade federal do rio de janeiro' => 'PROARQ-UFRJ',
    'programa de pós graduação em arquitetura da universidade federal do rio de janeiro (proarq ufrj) e faculdade de arquitetura e urbanismo da universidade federal do rio de janeiro (fau ufrj)' => 'PROARQ-UFRJ',
    'programa de pós-graduação em arquitetura da universidade federal do rio de janeiro -proarq/ufrj' => 'PROARQ-UFRJ',
    'programa de pós-graduação em arquitetura, universidade federal do rio de janeiro' => 'PROARQ-UFRJ',

    // PROURB-UFRJ (Programa de Pós-Graduação em Urbanismo)
    'prourb-ufrj' => 'PROURB-UFRJ',
    'prourb' => 'PROURB-UFRJ',
    'prourb ufrj' => 'PROURB-UFRJ',
    'prourb/fau-ufrj' => 'PROURB-UFRJ',
    'prourb-fau-ufrj' => 'PROURB-UFRJ',
    'prourb | fau-ufrj (programa de pós-graduação em urbanismo da faculdade de arquitetura da universidade federal do rio de janeiro)' => 'PROURB-UFRJ',
    'ufrj - prourb/fau' => 'PROURB-UFRJ',
    'professora substituta e pós-doutoranda na ufrj' => 'UFRJ',
    'programa de pós graduação em urbanismo da faculdade de arquitetura e urbanismo da universidade federal do rio de janeiro' => 'PROURB-UFRJ',
    'programa de pós-graduação em urbanismo (prourb) da faculdade de arquitetura e urbanismo da universidade federal do rio de janeiro (fau ufrj)' => 'PROURB-UFRJ',
    'programa de pós-graduação em urbanismo (prourb ufrj)' => 'PROURB-UFRJ',
    'programa de pós-graduação em urbanismo da faculdade de arquitetura e urbanismo da universidade federal do rio de janeiro' => 'PROURB-UFRJ',
    'pesquisador prourb/fau/ufrj, professor darf/fau/ufrj' => 'PROURB-UFRJ',
    'leu/ prourb/fau-ufrj' => 'PROURB-UFRJ',
    'ltds coppe ufrj / cbae ufrj' => 'UFRJ',

    // UFRGS e unidades
    'ufrgs' => 'UFRGS',
    'universidade federal do rio grande do sul' => 'UFRGS',
    'universidade federal do rio grande do sul (ufrgs)' => 'UFRGS',
    'aposentada ufrgs' => 'UFRGS',
    'professora ufrgs' => 'UFRGS',

    // FA-UFRGS (Faculdade de Arquitetura)
    'fa-ufrgs' => 'FA-UFRGS',
    'ufrgs - faculdade de arquitetura' => 'FA-UFRGS',
    'faculdade de arquitetura da univedrsidade federal do rio grande do sul - fa/ufrgs' => 'FA-UFRGS',
    'universidade federal do rio grande do sul, faculdade de arquitetura e programa de museologia e patrimônio' => 'FA-UFRGS',

    // PROPAR-UFRGS (Programa de Pesquisa e Pós-Graduação em Arquitetura)
    'propar-ufrgs' => 'PROPAR-UFRGS',
    'propar - ufrgs' => 'PROPAR-UFRGS',
    'propar/ufrgs' => 'PROPAR-UFRGS',
    'propar /ufrgs' => 'PROPAR-UFRGS',
    'propar/ ufrgs' => 'PROPAR-UFRGS',
    'propar' => 'PROPAR-UFRGS',
    'propar ufrgs' => 'PROPAR-UFRGS',
    'propar, ufrgs' => 'PROPAR-UFRGS',
    'ufrgs | propar' => 'PROPAR-UFRGS',
    'ufrgs - propar' => 'PROPAR-UFRGS',
    'ufrgs propar' => 'PROPAR-UFRGS',
    'programa de pesquisa e pós graduação em arquitetura - propar - faculdade de arquitetura da universidade federal do rio grande do sul - fa/ufrgs' => 'PROPAR-UFRGS',
    'universidade federal do rio grande do sul - propar' => 'PROPAR-UFRGS',
    'universidade federal do rio grande do sul- propar' => 'PROPAR-UFRGS',
    'universidade federal do rio grande do sul, propar' => 'PROPAR-UFRGS',
    'universidade federal do rio grande do sul -  ufrgs' => 'UFRGS',
    'universidade federal do rio grande do sul - ufrgs' => 'UFRGS',
    'propar - universidade federal do rio grande do sul (ufrgs)' => 'PROPAR-UFRGS',
    'programa de pesquisa e pós-graduação em arquitetura - propar, ufrgs' => 'PROPAR-UFRGS',
    'mestranda no programa de pós graduação em arquitetura propar da universidade federal do rio grande do sul ufrgs.' => 'PROPAR-UFRGS',
    'propar, ufrgs (aposentado)' => 'PROPAR-UFRGS',
    'faculdade de arquitetura - ufrgs' => 'FA-UFRGS',
    'departamento de arquitetura, universidade federal do rio grande do sul' => 'FA-UFRGS',
    'departamento de arquitetura /ufrgs' => 'FA-UFRGS',
    'universidade federal do rio grande do sul (ufrgs) faculdade de arquitetura e urbanismo' => 'FA-UFRGS',
    'ufrgs - programa de pós-graduação em museologia e patrimônio' => 'UFRGS',

    // UFBA e unidades
    'ufba' => 'UFBA',
    'universidade federal da bahia' => 'UFBA',
    'universidade federal da bahia (ufba)' => 'UFBA',
    'ufba - universidade federal da bahia' => 'UFBA',
    'universidade federal da bahia – professora efetiva' => 'UFBA',
    'docente efetiva da universidade federal da bahia' => 'UFBA',

    // FAUFBA (Faculdade de Arquitetura)
    'faufba' => 'FAUFBA',
    'fau-ufba' => 'FAUFBA',
    'faculdade de arquitetura da ufba' => 'FAUFBA',
    'faculdade de arquitetura da universidade federal da bahia/faufba' => 'FAUFBA',
    'faculdade de arquitetura da universidade federal da bahia' => 'FAUFBA',
    'faculdade de arquitetura - universidade federal da bahia' => 'FAUFBA',
    'faculdade de arquitetura - ufba' => 'FAUFBA',
    'faculdade de arquitetura – ufrgs' => 'FA-UFRGS',

    // PPGAU-UFBA (Programa de Pós-Graduação em Arquitetura e Urbanismo)
    'ppgau-ufba' => 'PPGAU-UFBA',
    'ppg-au/ufba' => 'PPGAU-UFBA',
    'ppgau/fa-ufba' => 'PPGAU-UFBA',
    'programa de pós graduação em arquitetura e urbanismo da universidade federal da bahia (ppg-au/ufba)' => 'PPGAU-UFBA',
    'programa de pós-graduação em arquitetura e urbanismo da universidade federal da bahia' => 'PPGAU-UFBA',
    'programa de pós-graduação em arquitetura e urbanismo da faculdade de arquitetura da universidade federal da bahia - ppgau/fa-ufba' => 'PPGAU-UFBA',

    // UFC
    'ufc' => 'UFC',
    'universidade federal do ceará' => 'UFC',
    'universidade federal do ceará (ufc)' => 'UFC',
    'ufc - universidade federal do ceará' => 'UFC',
    'universidade federal do ceará - ufc' => 'UFC',
    'programa de pós graduação em arquitetura e urbanismo e design da universidade federal do ceará' => 'UFC',
    'programa de pós-graduação em arquitetura, urbanismo e design da universidade federal do ceará' => 'UFC',

    // UFPE
    'ufpe' => 'UFPE',
    'universidade federal de pernambuco' => 'UFPE',
    'universidade federal de pernambuco (ufpe)' => 'UFPE',
    'ufpe - universidade federal do pernambuco' => 'UFPE',
    'ufpe- universidade federal de pernambuco' => 'UFPE',
    'professor aposentados da ufpe. professor permanente do ppgau-ufpb' => 'UFPE / UFPB',

    // UFCG
    'universidade federal de campina grande' => 'UFCG',
    'universidade federal de campina grande - ufcg' => 'UFCG',
    'ufcg' => 'UFCG',
    'ufcg - universidade federal de campina grande' => 'UFCG',
    'professora adjunta ufcg' => 'UFCG',
    'estudante de arquitetura e urbanismo na ufcg' => 'UFCG',

    // UFPA
    'ufpa' => 'UFPA',
    'universidade federal do pará' => 'UFPA',
    'universidade federal do pará - ufpa' => 'UFPA',
    'discente do ppgau - universidade federal do pará' => 'UFPA',
    'ppgau/ufpa' => 'PPGAU-UFPA',
    'programa de pós-graduação em arquitetura e urbanismo da universidade federal do pará (ppgau/ufpa)' => 'PPGAU-UFPA',

    // UFPB
    'ufpb' => 'UFPB',
    'universidade federal da paraíba' => 'UFPB',
    'universidade federal da paraiba' => 'UFPB',
    'discente - universidade federal da paraíba' => 'UFPB',
    'curso de arquitetura e urbanismo da universidade federal da paraíba.' => 'UFPB',

    // UFG
    'ufg' => 'UFG',
    'universidade federal de goiás' => 'UFG',
    'universidade federal de goias' => 'UFG',
    'universidade federal de goiás (ufg)' => 'UFG',
    'universidade federal de goiás, universidade federal da bahia' => 'UFG / UFBA',
    'ppgh- programa de pós-graduação em história - ufg' => 'UFG',

    // UTFPR
    'utfpr' => 'UTFPR',
    'universidade tecnológica federal do paraná' => 'UTFPR',
    'universidade tecnológica federal do paraná - utfpr' => 'UTFPR',
    'utfpr ( universidade tecnológica federal do paraná)' => 'UTFPR',
    'utfpr - universidade tecnológica federal do paraná' => 'UTFPR',

    // UFPR
    'ufpr' => 'UFPR',
    'universidade federal do paraná' => 'UFPR',

    // UFSC
    'ufsc' => 'UFSC',
    'universidade federal de santa catarina' => 'UFSC',
    'universidade federal de santa catarina (ufsc)' => 'UFSC',
    'professor adjunto do departamento de arquitetura e urbanismo da universidade federal de santa catarina - arq/ufsc' => 'UFSC',
    'universidade federal de santa catarina - departamento de arquitetura e urbanismo' => 'UFSC',

    // UFSM
    'ufsm' => 'UFSM',
    'universidade federal de santa maria' => 'UFSM',
    'universidade federal de santa maria-rs' => 'UFSM',
    'universidade federal de santa maria - campus cachoeira do sul' => 'UFSM',
    'professora substituta na universidade federal de santa maria' => 'UFSM',

    // UFSCar
    'ufscar' => 'UFSCar',
    'universidade federal de são carlos' => 'UFSCar',
    'universidade federal de são carlos - ufscar' => 'UFSCar',

    // UFPel
    'ufpel' => 'UFPel',
    'universidade federal de pelotas' => 'UFPel',

    // UFS (Sergipe)
    'professor - universidade federal de sergipe' => 'UFS',

    // UFMG
    'ufmg' => 'UFMG',
    'escola de arquitetura - universidade federal de minas gerais' => 'UFMG',
    'universidade federal de minas gerais' => 'UFMG',
    'universidade federal de minas gerais (mestrado em arquitetura e urbanismo)' => 'UFMG',

    // UFF
    'uff' => 'UFF',
    'universidade federal fluminense' => 'UFF',
    'universidade federal fluminense  uff' => 'UFF',
    'pesquisadora voluntária ppgau-uff' => 'UFF',

    // UFES
    'ufes' => 'UFES',
    'universidade federal do espírito santo' => 'UFES',
    'ufes (graduação) / ufba (mestrado)' => 'UFES / UFBA',

    // UFAL
    'universidade federal de alagoas' => 'UFAL',

    // UFRN
    'ufrn' => 'UFRN',
    'universidade federal do rio grande do norte' => 'UFRN',
    'ppgau-ufrn' => 'PPGAU-UFRN',
    'ppgau-ufrn e ppgau-ufrn; ces-coimbra' => 'PPGAU-UFRN',

    // UNIFAP
    'unifap' => 'UNIFAP',
    'universidade federal do amapá' => 'UNIFAP',
    'universidade federal do amapá-unifap' => 'UNIFAP',

    // UFRRJ
    'ufrrj' => 'UFRRJ',
    'ufrrj - universidade federal rural do rio de janeiro' => 'UFRRJ',
    'universidade federal rural do rio de janeiro' => 'UFRRJ',

    // UFRR
    'universidade federal de roraima' => 'UFRR',

    // UFAM
    'universidade federal do amazonas' => 'UFAM',
    'ufam' => 'UFAM',

    // UFPI
    'ufpi' => 'UFPI',
    'universidade federal do piauí' => 'UFPI',

    // UFERSA
    'ufersa' => 'UFERSA',

    // UFRR
    'ufrr' => 'UFRR',

    // UNIFESP
    'universidade federal de são paulo' => 'UNIFESP',

    // UFU
    'ufu' => 'UFU',
    'universidade federal de uberlândia' => 'UFU',
    'universidade federal de uberlândia- ufu' => 'UFU',
    'professora aponsentada da universidade federal de uberlândia' => 'UFU',
    'faued ufu' => 'FAUeD-UFU',

    // UFOP
    'ufop' => 'UFOP',
    'universidade federal de ouro preto' => 'UFOP',
    'universidade federal de ouro preto (ufop)' => 'UFOP',

    // UFSJ
    'ufsj' => 'UFSJ',

    // UFV
    'ufv' => 'UFV',
    'universidade federal de viçosa' => 'UFV',
    'universidade federal de viçosa (aposentada)' => 'UFV',
    'universidade federal de viçosa campus viçosa' => 'UFV',

    // UFCG
    'universidade federal de campina grande - pb' => 'UFCG',

    // UFS (Sergipe)
    'ufs' => 'UFS',
    'universidade federal de sergipe' => 'UFS',

    // UNIFESSPA
    'universidade federal do sul e sudeste do pará' => 'UNIFESSPA',

    // UFAL
    'ufal' => 'UFAL',
    'doutoranda - universidade federal de alagoas' => 'UFAL',

    // UnB
    'unb' => 'UnB',
    'universidade de brasília' => 'UnB',
    'universidade de brasília (unb)' => 'UnB',
    'universidade de brasília . unb' => 'UnB',
    'estudante de mestrado na universidade de brasília' => 'UnB',
    'unb (pesquisa) - fox engenharia (iniciativa privada)' => 'UnB',
    'professora adjunta da faculdade de arquitetura e urbanismo da universidade de brasília' => 'UnB',
    'faculdade de arquitetura e urbanismo da universidade de brasília' => 'FAU-UnB',
    'fau/unb' => 'FAU-UnB',
    'fau unb' => 'FAU-UnB',

    // UFMS
    'ufms' => 'UFMS',

    // UEM
    'uem' => 'UEM',
    'universidade estadual de maringá' => 'UEM',
    'universidade estadual de maringá (uem)' => 'UEM',
    'universidade estadual de maringá .' => 'UEM',
    'programa associado uem/uel de pós-graduação em arquitetura e urbanismo' => 'UEM / UEL',
    'universidade estadual de maringá ppu uem/uel' => 'UEM / UEL',

    // UEL
    'uel - universidade estadual de londrina' => 'UEL',
    'unifil' => 'UniFil',
    'centro universitário filadélfia-unifil' => 'UniFil',

    // UEMA
    'universidade estadual do maranhâo uema' => 'UEMA',
    'uema/ifma' => 'UEMA / IFMA',
    'uema - universidades estadual do maranhão - depto de arquitetura e mestrado em desenvolvimento socioespacial e regional  ppdsr uema' => 'UEMA',

    // UEFS
    'uefs' => 'UEFS',

    // UDESC
    'udesc' => 'UDESC',
    'universidade do estado de santa catarina' => 'UDESC',
    'professor na udesc - universidade do estado de santa catarina' => 'UDESC',

    // UPE
    'escola politécnica da universidade de pernambuco - poli/upe' => 'UPE',

    // UNEB
    'universidade do estado da bahia' => 'UNEB',

    // Mackenzie
    'mackenzie' => 'Mackenzie',
    'universidade presbiteriana mackenzie' => 'Mackenzie',
    'universidade presbiteriana mackenzie9' => 'Mackenzie',
    'universidade presbisteriana mackenzie' => 'Mackenzie',
    'universidade mackenzie' => 'Mackenzie',
    'universidade presbiteriana mackenzie sp' => 'Mackenzie',
    'instituto presbiteriano mackenzie' => 'Mackenzie',
    'fau- universidade presbiteriana mackenzie' => 'Mackenzie',
    'fau mackenzie' => 'FAU-Mackenzie',
    'fau-mackenzie' => 'FAU-Mackenzie',
    'faculdade de arquitetura e urbanismo / universidade presbiteriana mackenzie' => 'FAU-Mackenzie',
    'faculdade de arquitetura e urbanismo da universidade presbiteriana mackenzie' => 'FAU-Mackenzie',
    'faculdade de arquitetura da universidade presbiteriana mackenzie' => 'FAU-Mackenzie',
    'faculdade de arquitetura e urbanismo mackenzie' => 'FAU-Mackenzie',
    'universidade presbiteriana mackenzie - faculdade de arquitetura e urbanismo' => 'FAU-Mackenzie',
    'professora, faculdade de arquitetura e urbanismo, universidade presbiteriana mackenzie' => 'FAU-Mackenzie',
    'professor do mackenzie' => 'Mackenzie',
    'estudante no ppgau fau-mack' => 'FAU-Mackenzie',
    'mackenzie e escola da cidade' => 'Mackenzie / Escola da Cidade',
    'faap ( docente)  / universidade presbiteriana mackenzie ( doutoranda)' => 'FAAP / Mackenzie',

    // PUC
    'pontifícia universidade católica de campinas' => 'PUC-Campinas',
    'professor pucpr' => 'PUC-PR',
    'pucpr' => 'PUC-PR',
    'pontifícia universidade católica do paraná pucpr' => 'PUC-PR',
    'puc-rio' => 'PUC-Rio',
    'puc - rio' => 'PUC-Rio',
    'pontifícia universidade católica do rio de janeiro' => 'PUC-Rio',
    'pontifícia  universidade católica do rio de janeiro (puc-rio)' => 'PUC-Rio',
    'puc minas' => 'PUC-Minas',
    'pontifícia universidade católica de minas gerais' => 'PUC-Minas',
    'pontifícia universidade católica de minas gerais- campus de poços de caldas' => 'PUC-Minas',
    'professor: puc minas / doutorando: politecnico di milano' => 'PUC-Minas',
    'pontifícia universidade católica do rio grande do sul' => 'PUC-RS',

    // São Judas
    'universidade são judas tadeu' => 'Universidade São Judas Tadeu',
    'programa de pós-graduação em arquitetura e urbanismo, universidade são judas tadeu' => 'Universidade São Judas Tadeu',

    // FURB
    'universidade regional de blumenau - furb' => 'FURB',

    // UNICAMP
    'unicamp' => 'UNICAMP',
    'universidade estadual de campinas' => 'UNICAMP',
    'universidade estadual de campinas unicamp' => 'UNICAMP',
    'universidade estadual de campinas (unicamp)' => 'UNICAMP',
    'unicamp – universidade estadual de campinas' => 'UNICAMP',
    'fec-fau unicamp (faculdade de engenharia civil, arquitetura e urbanismo da universidade estadual de campinas)' => 'UNICAMP',
    'faculdade de  engenharia civil ,arquitetura e urbanismo da universidade estadual de campinas' => 'UNICAMP',
    'universidade estadual de campinas, fecfau unicamp' => 'UNICAMP',

    // UNIJUÍ
    'unijuí' => 'UNIJUÍ',

    // Outras universidades
    'universidade paulista' => 'UNIP',
    'universidade de coimbra' => 'Universidade de Coimbra',
    'universidade de lisboa' => 'Universidade de Lisboa',
    'universidade cruzeiro do sul' => 'Universidade Cruzeiro do Sul',
    'uni7' => 'UNI7',
    'cesupa' => 'CESUPA',
    'facunicamps - faculdade unida de campinas, goiás' => 'FacUnicamps',

    // UNDB
    'centro universitário dom bosco - undb' => 'UNDB',
    'centro universitário undb / secretaria de estado de articulação política - secap' => 'UNDB',
    'centro universitário undb' => 'UNDB',
    'undb e governo do estado' => 'UNDB',

    // UNIBRA
    'unibra' => 'UNIBRA',
    'unibra e focca' => 'UNIBRA / FOCCA',

    // ESUDA
    'esuda - professora' => 'ESUDA',
    'faculdade de ciências humanas esuda' => 'ESUDA',
    'facudade esuda' => 'ESUDA',

    // FACENS
    'professora do curso de graduação em arquitetura e urbanismo do centro universitário facens - sorocaba - sp' => 'FACENS',

    // Escola da Cidade
    'escola da cidade' => 'Escola da Cidade',
    'associação escola da cidade - faculdade de arquitetura' => 'Escola da Cidade',
    'escola da cidade -faculdadde de arquitetura' => 'Escola da Cidade',

    // Universidade Positivo
    'universidade positivo' => 'Universidade Positivo',
    'professor - universidade positivo' => 'Universidade Positivo',
    'utfpr, up' => 'UTFPR / Universidade Positivo',

    // UEMA
    'uema' => 'UEMA',
    'universidade estadual do maranhão' => 'UEMA',
    'universidade estadual do maranhaõ' => 'UEMA',
    'uema universidade estadual do maranhão' => 'UEMA',

    // UEG
    'ueg' => 'UEG',
    'universidade estadual de goiás' => 'UEG',

    // UNESP
    'unesp' => 'UNESP',
    'universidade estadual paulista júlio de mesquita filho' => 'UNESP',

    // UPE (Pernambuco)
    'upe' => 'UPE',
    'universidade de pernambuco' => 'UPE',

    // Unisinos
    'unisinos' => 'Unisinos',
    'universidade do vale do rio dos sinos (unisinos), universidade federal do rio grande do sul (ufrgs)' => 'Unisinos / UFRGS',

    // Univali
    'univali' => 'UNIVALI',

    // UNIFESP
    'unifesp' => 'UNIFESP',
    'universidade federal de são paulo (unifesp)' => 'UNIFESP',

    // UFSC
    'universidade federal de santa catarina, departamento de arquitetura e urbanismo, programa de pós-graduação em arquitetura e urbanismo' => 'UFSC',
    'universidade federal de santa catarina (ufsc), departamento de arquitetura e urbanismo' => 'UFSC',
    'universidade federal de santa catarina e universidade de são paulo' => 'UFSC / USP',

    // UFPR
    'ufpr, curso de arquitetura e urbanismo.' => 'UFPR',

    // Outras universidades
    'centro universitário da amazônia uniesamaz' => 'UNIESAMAZ',
    'centro universitário una, unibh, estúdio muvuca' => 'UNA / UniBH',
    'faculdade uniessa - instituto pater de educação e cultura' => 'Uniessa',
    'estudante na faculdades unyleya' => 'Unyleya',

    // IFCE
    'instituto federal de educação, ciência e tecnologia do ceará' => 'IFCE',

    // Fiocruz
    'fiocruz' => 'Fiocruz',
    'fundação oswaldo cruz' => 'Fiocruz',
    'instituição pública federal: fundação oswaldo cruz' => 'Fiocruz',
    'dph/coc/fiocruz e abdeh- associação para o desenvolvimento do edifício hospitalar' => 'Fiocruz',
    'fiocruz - casa de oswaldo cruz' => 'Fiocruz',
    'abdeh; coc/fiocruz' => 'Fiocruz / ABDEH',
    'casa de oswaldo cruz - fiocruz' => 'Fiocruz',
    'abdeh e fiocruz' => 'Fiocruz / ABDEH',
    'programa de pós graduação em preservação e gestão do patrimônio cultural das ciências e da saúde, casa de oswaldo cruz, fundação oswaldo cruz. casa de oswaldo cruz, fundação oswaldo cruz.' => 'Fiocruz',

    // ESUDA
    'esuda' => 'ESUDA',
    'faculdade de ciências humanas - esuda' => 'ESUDA',

    // UFAM
    'ufam - universidade federal do amazonas' => 'UFAM',

    // UNIFEBE
    'unifebe' => 'UNIFEBE',
    'unifebe - centro universitário de brusque' => 'UNIFEBE',
    'unifebe - centro universitario da fundacao educacional de brusque' => 'UNIFEBE',
    'caixa economica federal. unifebe.' => 'CAIXA / UNIFEBE',
    'professor na unifebe' => 'UNIFEBE',

    // Institutos Federais
    'instituto federal farroupilha' => 'IFFar',
    'instituto federal fluminense' => 'IFF',
    'instituto federal de brasíilia' => 'IFB',
    'instituto federal de educação, ciência e tecnologia de rondônia – campus vilhena' => 'IFRO',

    // ESDI
    'escola superior de desenho industrial - esdi / uerj' => 'ESDI-UERJ',
    'doutoranda pela escola superior de design industrial - esdi-uerj' => 'ESDI-UERJ',

    // FAAP
    'faap' => 'FAAP',

    // IPHAN
    'iphan' => 'IPHAN',
    'iphan e universidade veiga de almeida' => 'IPHAN / UVA',
    'iphan (aposentada)' => 'IPHAN',

    // Instituto Armando de Holanda
    'instituto armando de holanda' => 'Instituto Armando de Holanda Cavalcanti',

    // Órgãos públicos
    'câmara dos deputados' => 'Câmara dos Deputados',
    'senado federal' => 'Senado Federal',
    'prefeitura da cidade do rio de janeiro' => 'Prefeitura do Rio de Janeiro',
    'prefeitura da cidade do rio de janeiro- secretaria municipal de planejamento urbano' => 'Prefeitura do Rio de Janeiro',
    'prefeitura municipal de são paulo' => 'Prefeitura de São Paulo',
    'prefeitura municipal de fortaleza (secretaria municipal da cultura de fortaleza)' => 'Prefeitura de Fortaleza',
    'prefeitura do recife' => 'Prefeitura do Recife',
    'ministério da economia/ governo federal' => 'Ministério da Economia',
    'ministério da economia. governo federal' => 'Ministério da Economia',
    'caixa economica federal' => 'CAIXA',
    'united nations office for project services (unops)' => 'UNOPS',

    // Autônomo / Profissional Liberal
    'autônomo' => 'Autônomo',
    'profissional liberal' => 'Autônomo',
    'arquiteta profissional liberal' => 'Autônomo',
    'mei' => 'Autônomo',
    'profissional liberal e estudante de mestrado' => 'Autônomo',
    'universidade e profissional liberal' => 'Autônomo',
    'atualmente nenhuma' => '',
    'nada consta' => '',
    'não possuo' => '',
    'nenhum' => '',
    'não' => '',
    '-' => '',
    '.' => '',
    'nao sou professor' => '',
    'não sou estudante' => '',
    'não sou professor nem estudante.' => '',
    'não sou professora nem estudante' => '',
    'não estou professora nesse momento' => '',
    'não aplicável' => '',
    'não se aplica' => '',
    'não se aplica.' => '',
    'sem filiação no momento' => '',
    'professora universitária aposentada/servidora pública federal' => '',
    'pesquisadora pela capes.' => '',
    'pesquisador independente' => '',
    'pesquisadora independente' => '',
    'arquiteta e urbanista e, pesquisadora independente' => '',

    // Empresas (manter como está ou simplificar)
    'empresa' => '',
    'cmsp studio / caixa econômica federal' => 'CAIXA',
    'empresa : diretora de operações da empresa concrejato serviçostécnicos de eng.' => 'Concrejato',
    'r.v. cattani arquitetura ltda' => 'RV Cattani Arquitetura',
    'alfa delta rio projetos' => 'Alfa Delta Rio',
    'arco it' => 'Arco IT',
    'bossa furniture' => 'Bossa Furniture',
    'diretora da a2 arquitetura' => 'A2 Arquitetura',
    'estúdio trilho arquitetura e urbanismo' => 'Estúdio Trilho',
    'fundação mario leal ferreira' => 'Fundação Mario Leal Ferreira',
    'instituto armando de holanda cavalcanti' => 'Instituto Armando de Holanda Cavalcanti',
    'peta arquitetos associados' => 'PETA Arquitetos',
    'metropole arquitetos ltda' => 'Metrópole Arquitetos',

    // Universidades Internacionais
    'universidad politécnica de madrid' => 'Universidad Politécnica de Madrid',
    'institut superior técnico - universidade de lisboa' => 'IST Lisboa',
    'instituto superior técnico - universidade de lisboa' => 'IST Lisboa',
    'faculdade de arquitetura da universidade do porto' => 'FAUP',
    'kadu tomita - faculdade de arquitectura da universidade do porto' => 'FAUP',
    'ceau faup - centro de estudos de arquitetura e urbanismo (universidade do porto, portugal)' => 'FAUP',
    'universidad de la república, facultad de arquitectura, diseño y urbanismo, instituto de proyecto-centro de teoría; sistema nacional de investigadores' => 'Universidad de la República',
    'universidad de la república, facultad de arquitectura, diseño y urbanismo, instututo de proyecto, departamento de arquitectura interior y mobiliario' => 'Universidad de la República',
    'fadu – facultad de arquitectura, diseño y urbanismo, universidad de la república. montevideo, uruguay' => 'Universidad de la República',
    'facultad de arquitectura diseño y urbanismo de la udelar uruguay' => 'Universidad de la República',
    'universidad nacional de colombia / facultad de artes / sede bogotá / escuela de arquitectura y urbanismo' => 'Universidad Nacional de Colombia',
    'directora escuela arquitectura universidad finis terrae' => 'Universidad Finis Terrae',
    'logan leyton: pontificia universidad catolica de chile; claudio galeno ibaceta: universidad católica del norte' => 'PUC Chile',
    'ingrid quintana-guerrero, universidad de los andes, colômbia. margarita roa, universidad de san buenaventura, colômbia' => 'Universidad de los Andes',
    'universidad torcuato di tella' => 'Universidad Torcuato Di Tella',
    'instituto de arte americano e investigaciones estéticas "mario j. buzchiazzo". facultad de arquitectura, diseño y urbanismo. universidad de buenos aires.' => 'Universidad de Buenos Aires',
    'chelsea college of arts, ual' => 'Chelsea College of Arts',
    'columbia university & pratt institute' => 'Columbia University / Pratt Institute',
    'miami university - oxford, ohio, estados unidos' => 'Miami University',
    'professor in practice university of miami school of architecture' => 'University of Miami',
    'école d\'architecture, université de montréal, canadá' => 'Université de Montréal',
    'college of architecture and urban planning (caup), tongji university' => 'Tongji University',
    'college of architecture and urban planning (caup), tongji university, shanghai, china' => 'Tongji University',
    'tongji university - shanghai china' => 'Tongji University',
    'universidade de sevilha e universidade de são paulo' => 'Universidad de Sevilla / USP',
    'universidade da coruña; universidade federal do rio grande do sul' => 'Universidade da Coruña / UFRGS',
    'facultad de arquitectura, urbanismo y diseño. universidad nacional de córdoba' => 'Universidad Nacional de Córdoba',
    'instituto de investigaciones estéticas, unam' => 'UNAM',
    'facultad de arquitectura / universidad nacional autónoma de méxico' => 'UNAM',

    // Outras instituições brasileiras
    'unisena' => 'UniSenac',
    'uningá' => 'Uningá',
    'centro universitário ingá - uningá' => 'Uningá',
    'unima | afya - centro universitário de maceió' => 'UNIMA',
    'unima - afya' => 'UNIMA',
    'universidade católica de santos. faculdade de arquitetura e urbanismo.' => 'UniSantos',
    'centro universitário belas artes de são paulo' => 'Belas Artes',
    'universidade anhembi morumbi' => 'Anhembi Morumbi',
    'universidade veiga de almeida' => 'UVA',
    'centro universitário anhanguera / campus santana' => 'Anhanguera',
    'faculdade ideal - faci wyden' => 'FACI Wyden',
    'universidade ceuma' => 'CEUMA',
    'professor fae centro universitário' => 'FAE',
    'universidade da amazônia - unama' => 'UNAMA',
    'centro universitário facens' => 'FACENS',
    'curso de arquitetura e urbanismo - centro universitário facens' => 'FACENS',
    'ibmec' => 'IBMEC',
    'professor no centro universitário ibmr' => 'IBMR',
    'estudante de doutorado na universidade de são paulo e professora no ibmec' => 'USP / IBMEC',
    'inbec' => 'INBEC',
    'uni7' => 'UNI7',
    'uni7 e unichristus' => 'UNI7 / Unichristus',
    'centro universitário unichristus' => 'Unichristus',
    'católica de santa catarina' => 'Católica de SC',
    'centro universitário newton paiva' => 'Newton Paiva',
    'professor cesusc' => 'CESUSC',
    'docente do centro tecnico templo da arte' => '',
    'fundação joaquim nabuco' => 'Fundaj',
    'santa úrsula' => 'Santa Úrsula',
    'centro de preservação cultural da universidade de são paulo' => 'CPC-USP',
    'universidade do estado de minas gerais - escola de design' => 'UEMG',
    'professora da escola de comunicação e artes da universidade de são paulo' => 'ECA-USP',
    'universidade são judas tadeu (usjt)' => 'Universidade São Judas Tadeu',

    // Combinações múltiplas
    'larissa: universidade presbiteriana mackenzie/ universidade paulista. bianca: universidade presbiteriana mackenzie' => 'Mackenzie / UNIP',
    'pilar: universidade estácio de sá (unesa) de niterói, lígia: prourb ufrj' => 'Estácio / PROURB-UFRJ',
    'ufpe ufrn ufpb aposentada' => 'UFPE / UFRN / UFPB',
    'unb - idp - ceub' => 'UnB / IDP / CEUB',
    'programa de pós graduação, faculdade de arquitetura e urbanismo, universidade de brasília' => 'FAU-UnB',
    'programa de pós-graduação faculdade de arquitetura e urbanismo universidade de brasília (ppg fau unb)' => 'FAU-UnB',
    'fau-unb' => 'FAU-UnB',
    'iab/rj; samn-associação de amigos do museu nacional' => 'IAB-RJ',
    'instituto de arquitetos do brasil  e conselho de arquitetura e urbanismo' => 'IAB / CAU',
    'docomomo brasil' => 'Docomomo Brasil',
    'oficina de mosaicos' => 'Oficina de Mosaicos',
    'instituto memória da arquitetura brasileira - imearb' => 'IMEARB',
    'escola crítica espaço e território' => 'Escola Crítica',
    'metropole arquitetos' => 'Metrópole Arquitetos',

    // Aposentados / genéricos
    'aluna mestranda e professor orientador.' => '',
    'atualmente: nenhuma' => '',
    'não filiada / profissional de assessoria técnica' => '',
    'ex-professora.' => '',
    'professor (em período sabático).' => '',
    'já fui filiado anteriormente' => '',
    'arquiteto da diretoria de arquitetura e engenharia da polícia civil do distrito federal.' => '',
    '—' => '',
    'nathalia cantergiani' => '', // Nome digitado no campo errado

    // Lixo / erros
    '617.665.853-53' => '', // CPF digitado errado
    'universidade' => '',
];
