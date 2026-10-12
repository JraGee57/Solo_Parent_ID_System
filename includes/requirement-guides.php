<?php
/** Researched 12 October 2026. Procedures are guidance, not additional checklist requirements. */
$psaDirectory = 'https://psa.gov.ph/directory/bicol-region';
$psaLocal = 'https://rsso05.psa.gov.ph/content/full-implementation-civil-registration-service-appointment-system-crs-outlet-virac';
$psaRequests = 'https://psa.gov.ph/civilregistration';
$psaAuthority = 'https://rsso04a.psa.gov.ph/content/mga-alituntunin-sa-pag-isyu-ng-birth-certificate-death-certificate-marriage-certificate';
$municipal = 'https://virac-catanduanes.gov.ph/municipal-social-welfare-development-office/';
$civilRegistry = 'https://virac-catanduanes.gov.ph/municipal-civil-registry-office/';
$law = 'https://pcw.gov.ph/republic-act-no-11861-expanded-solo-parents-welfare-act/';
$notarial = 'https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/17/63384';
$pao = 'https://pao.gov.ph/wp-content/uploads/2025/04/PAO-Citizens-Charter-Handbook-2024-1st-Edition.final_.Rev_.4.pdf';
$courts = 'https://sc.judiciary.gov.ph/court-locator/';
$nacc = 'https://www.nacc.gov.ph/wp-content/uploads/2026/03/NACC-Citizens-Charter-2026-2nd-Edition.pdf';
$medical = 'https://fmogh.doh.gov.ph/wp-content/uploads/2026/01/FMOGH-CC-REMOVED-SIGNATORIES-1.pdf';
$ebmc = 'https://ntp.doh.gov.ph/view-facility/?id=4331';
$guide = static function (string $where, array $prepare, array $steps, array $sources, string $basis, array $notes = []): array {
    return compact('where', 'prepare', 'steps', 'sources', 'basis', 'notes');
};
$guides = [];

$records = [
    'birthCert' => ['each child’s birth certificate', ['Each child’s complete name, date/place of birth, father’s name, and mother’s maiden name.', 'Your valid ID; proof of parenthood/guardianship or authorization if PSA requests it.'], 'https://psa.gov.ph/birth-certificate'],
    'marriageCert' => ['your marriage certificate', ['Both spouses’ full names and the date/place of marriage.', 'Your valid ID and the record details; authorization if requesting for someone else.'], 'https://psa.gov.ph/marriage-certificate'],
    'deathCert' => ['your spouse’s death certificate', ['Deceased spouse’s full name and date/place of death.', 'Your ID and proof of relationship/authority if requested.'], 'https://psa.gov.ph/death-certificate'],
    'cenomar' => ['your Certificate of No Marriage (CENOMAR)', ['Your full name, birthdate/place, father’s name, and mother’s maiden name.', 'Your valid ID and the number of copies needed.'], 'https://psa.gov.ph/cenomar'],
];
foreach ($records as $key => [$record, $prepare, $reference]) {
    $guides[$key] = $guide('PSA CRS Outlet Virac, at PSA Catanduanes in Cavinitan; online delivery is another option.', $prepare, [
        'Check whether your checklist needs a PSA copy, certified true copy, or photocopy of ' . $record . '.',
        'Book PSA CRS Outlet Virac through appointment.psa.gov.ph, using the actual requester’s name.',
        'Bring the appointment slip, ID, record details, and any required authorization. Complete the request form at the outlet.',
        'Pay the document fee at the authorized cashier, keep the receipt, and follow the release instructions.',
        'Check the released document for correct names and dates. Keep the original and prepare the copy MSWDO asks for.',
    ], ['PSA record details' => $reference, 'Virac appointment advisory (2026)' => $psaLocal, 'PSA Bicol office directory' => $psaDirectory, 'PSA requester/authorization guidance' => $psaAuthority, 'PSA online request channels' => $psaRequests], 'PSA national procedure with a confirmed Virac appointment rule', [
        'Booking is free; document issuance has a separate fee. Confirm the current amount and release schedule with PSA.',
        'For delivery, start from PSA’s official civil-registration page and follow its linked PSAHelpline or PSA Serbilis service. Check current charges, payment, identity checks, and delivery coverage before ordering.',
        'If PSA returns a negative result or the entry is incorrect, contact the civil registrar where the event was registered. For events registered in Virac, ask Virac MCRO about record verification or endorsement to PSA; do not assume a local copy replaces a required PSA copy.',
    ]);
}

$guides['brgyCert'] = $guide('The barangay hall where you currently reside in Virac.', ['An available valid ID and your complete address.', 'Children’s details and the exact residency/solo-parent certificate wording from your checklist.'], [
    'Show the barangay secretary or designated staff your application checklist and explain that the certificate is for a Solo Parent ID.',
    'Ask for the barangay’s form and what residency or household evidence it needs. Complete the requested details.',
    'Allow the barangay to verify residence and the statements it can certify. Ask about the authorized signatory.',
    'Confirm any authorized charge and the collection date, then collect the signed certificate and check its spelling and purpose.',
], ['Solo-parent documentary requirements' => $law], 'National requirement; your barangay’s request process must be confirmed locally', ['A certificate of indigency, ordinary clearance, and solo-parent/residency certification serve different purposes. Request the document named in your checklist. No Virac-wide barangay fee or release time was verified.']);

$guides['validId'] = $guide('Use an existing accepted government ID. For a National ID, ask PSA Catanduanes about the current registration site in Virac.', ['Your existing ID, or supporting identity documents listed by PSA for National ID registration.', 'If already registered, your transaction slip or existing National ID details.'], [
    'Ask MSWDO which available ID and format it accepts before requesting another card.',
    'For a new National ID registration, check PSA’s registration-center list and supporting-documents page.',
    'Bring the accepted originals to the registration center. Review the recorded personal details and complete the biometric capture.',
    'Keep your transaction slip and follow PSA’s instructions for obtaining or checking available National ID formats.',
    'Present the accepted ID to MSWDO and prepare the required copy.',
], ['National ID registration process' => 'https://philsys.gov.ph/registration-process/', 'National ID supporting documents' => 'https://philsys.gov.ph/supporting-documents/', 'PSA Catanduanes office' => $psaDirectory], 'National ID procedure; confirm the active Virac registration site and MSWDO’s accepted format', ['A PSA civil-record appointment and National ID registration are different services. Do not assume one appointment covers both.']);

foreach (['photo1x1' => '1x1'] as $key => $size) {
    $guides[$key] = $guide('Any photo studio or ID-photo service in Virac; this is not a government-issued certificate.', ['The checklist’s required size, quantity, and any MSWDO background instructions.'], [
        'Show the studio the requirement and request ' . $size . ' ID photos in the quantity specified.',
        'Ask the price and confirm the background and print size before the photo is taken.',
        'Check that your face is clear and the prints are the correct size. Keep extra copies if you choose.',
    ], [], 'Preparation advice based on your configured checklist', ['Follow the size and quantity in the selected category’s checklist. The studio sets its own price.']);
}

$affidavitSources = ['Traditional notarization: identity and appearance' => $notarial, 'PAO services and Virac office (2024 charter)' => $pao];
foreach ([
    'soleCareAffidavit' => ['your own affidavit of parental care/support', 'Your ID, children’s names, and accurate care/support and household details.'],
    'witnessAffidavit' => ['the affidavit of the two disinterested persons specified in the checklist', 'The two witnesses’ IDs and their own knowledge of the facts; confirm with MSWDO who qualifies.'],
] as $key => [$document, $prepare]) {
    $guides[$key] = $guide('MSWDO Virac for accepted wording; a commissioned notary for execution, or PAO Virac for assessment of available assistance.', [$prepare, 'The MSWDO format and any records supporting the statements.'], [
        'Get the accepted wording for ' . $document . ' from MSWDO before paying for a document.',
        'Complete the factual details. Each person signing must review and understand their own statements.',
        'Ask a commissioned notary about the required ID, appointment, fee, and signing arrangements. For traditional notarization, appear with the unsigned affidavit and sign as instructed.',
        'Collect the properly executed document and check the names, dates, signature, and notarial details.',
    ], $affidavitSources, 'National notarization guidance; local template and charges to confirm', ['Ask PAO whether you qualify for assistance and whether it can handle this affidavit. Its published Virac listing is DOJ Building, San Isidro Village; confirm arrangements before visiting.', 'Electronic notarization has separate Supreme Court rules. Do not assume an ordinary video call or emailed signature is an accepted notarization.']);
}

$guides['barangayCareAffidavit'] = $guide('Your Virac barangay official, coordinated with MSWDO; the official is the affidavit’s signer.', ['The exact MSWDO wording and your residency details.', 'Children’s records and care/support information the official needs to verify.'], [
    'Ask MSWDO whether it requires a barangay official’s sworn affidavit rather than an ordinary certificate.',
    'Take the accepted format to the barangay secretary and request verification by the appropriate official.',
    'Provide the household details and supporting records requested by the barangay.',
    'Ask how the official will execute the oath and obtain the correctly signed/sworn copy for MSWDO.',
], ['Solo-parent requirements and barangay affidavit' => $law, 'Traditional notarization guidance' => $notarial], 'National requirement; local execution arrangements to confirm', ['Do not sign in place of the barangay official. An applicant’s own affidavit and the official’s affidavit are different documents.']);

$guides['abandonmentRecord'] = $guide('The barangay office or police station where the incident was recorded. For a Virac report, ask the relevant barangay or Virac Municipal Police Station.', ['Your ID; parties’ names, incident/report date, and entry number if known.', 'The checklist wording and any copy of an existing report.'], [
    'Check which office holds the existing entry and ask for its records/duty officer.',
    'Explain that you need a certified record or extract for MSWDO and provide the search details.',
    'Ask about the request form, authorization/privacy checks, any official fee, and release arrangements.',
    'Check that the released record identifies the incident and relevant dates and is signed/certified as required.',
    'If no incident was previously recorded, explain that truthfully and ask MSWDO what evidence is acceptable; a new entry does not prove an earlier report existed.',
], ['Solo-parent documentary provisions and assistance' => $law], 'Suggested records-request route; the local station/barangay procedure is not verified', ['For abuse-related matters, ask MSWDO for confidential referral to the barangay VAW desk or police Women and Children Protection Desk.']);

$guides['judicialDecree'] = $guide('The branch/records office of the court that issued your existing decision, or your lawyer. Use the Supreme Court locator for Virac branches.', ['Your ID, case number, case title, branch, and decision date.', 'Any existing decision and MSWDO’s requested certification wording.'], [
    'Identify the issuing court from your decision; a Virac address does not mean the decision is held by a Virac court.',
    'Contact the branch clerk/records office or your lawyer and ask for a certified true copy of the existing decree.',
    'Ask MSWDO whether a certificate of finality, entry of judgment, or an annotated marriage record is also needed for your checklist.',
    'Submit the court’s request and ID/authorization documents, pay only the official assessed charge, and keep the receipt.',
    'Collect and check the certified pages and any separately requested certifications.',
], ['Supreme Court court locator' => $courts, 'PSA marriage records' => 'https://psa.gov.ph/marriage-certificate'], 'Suggested route for obtaining an existing court record; branch procedure to confirm', ['This retrieves an existing order. If you do not have one, consult a lawyer/PAO and MSWDO about your situation; a barangay certificate cannot create a court decree.']);

$guides['separationProof'] = $guide('The issuing court for an existing legal-separation decree; MSWDO and a notary for the checklist’s witness-affidavit option.', ['Existing case details, or the two witnesses and identification specified in your checklist.'], [
    'Show MSWDO your checklist and ask which proof fits the recorded circumstances.',
    'For a decree, request a certified copy from the issuing court’s branch/records office.',
    'For a witness affidavit, get MSWDO’s format and confirm who may be a disinterested witness.',
    'Arrange execution with the required signers and IDs, and collect the accepted copy.',
], ['Supreme Court court locator' => $courts, 'Traditional affidavit execution' => $notarial], 'Checklist-specific alternatives; MSWDO must confirm the applicable proof', ['A witness affidavit and a judicial legal-separation decree are different kinds of evidence.']);

$guides['guardianshipProof'] = $guide('The court holding an existing guardianship order, or NACC/RACCO V for adoption or foster-care records.', ['Your ID, child’s details, and case/order/placement reference.', 'A copy of the existing order or placement/adoption document, if available.'], [
    'Ask MSWDO which existing document matches your caregiving arrangement.',
    'For guardianship, contact the court that issued the order and request the certified copy.',
    'For adoption or foster care, contact the issuing NACC/RACCO office first and ask which copy it can release and what authority is required.',
    'RACCO V’s 2026 directory lists its office in Legazpi; call or email before arranging mainland travel.',
    'Complete the issuer’s request, collect the accepted document, and retain your original records.',
], ['NACC 2026 charter and RACCO V directory' => $nacc, 'Court locator' => $courts], 'NACC regional office verified; existing-record request arrangements to confirm', ['RACCO V: 2nd Floor, Tyler Building, Rosal Street, Barangay 35-Tinago, Legazpi City; 0917 191 6222; racco5@nacc.gov.ph.', 'Starting an adoption, foster-care, or guardianship process is different from requesting a copy. Ask the responsible authority for assessment if no order/placement document exists.']);

$guides['detentionCertificate'] = $guide('The records unit/warden of the jail or correctional facility that holds the spouse’s detention or sentence record.', ['Your ID, spouse’s full name, facility, and available case/PDL number.', 'The exact certification and period requested by your checklist; proof of relationship/authority if requested.'], [
    'Locate the facility holding the record; ask its records unit about a certificate of detention or sentence certification.',
    'Explain the MSWDO purpose and ask which request/authorization documents it requires.',
    'Submit the request and follow its verification and release process.',
    'Check the certification’s dates, facility, signer, and coverage against the checklist.',
], ['BJMP charter: detention certification service' => 'https://r1.bjmp.gov.ph/wp-content/uploads/2023/12/03.26.2022_MC_ON_CITIZENS_CHARTER.pdf'], 'BJMP service reference; the actual facility’s current process must be confirmed', ['Do not assume a police blotter is a jail certificate. Provincial jails, BJMP facilities, and BuCor prisons may have different records procedures.']);

$guides['complaintAffidavit'] = $guide('MSWDO Virac for confidential referral; the police/prosecutor/court holding an existing complaint, or PAO/a lawyer for preparation.', ['Your ID, an existing complaint reference if any, and relevant records you can safely provide.'], [
    'Ask MSWDO privately which complaint-affidavit document your checklist requires.',
    'If already filed, contact the office holding it and request the required certified copy, following its access rules.',
    'If a new affidavit is needed, ask PAO/a lawyer or the responsible protection/complaints desk for help recording your own account.',
    'Follow the authorized officer’s oath/signing instructions and obtain the accepted copy.',
], ['PAO counseling and documentation services' => $pao, 'Solo-parent assistance provisions' => $law], 'Referral and preparation guidance; case-specific filing procedure to confirm', ['A complaint affidavit, police record, and medical record are separate documents. Ask MSWDO which applies before arranging requests.']);

$guides['incidentMedicalRecord'] = $guide('The medical-records/Health Information Management office of the hospital or clinic that actually treated the patient.', ['Patient’s name, consultation/admission dates, and record number if known.', 'Patient/requester ID and any patient or guardian authorization required by that hospital.'], [
    'Ask MSWDO whether it needs a medical certificate, clinical abstract, or medico-legal record.',
    'Contact the treating facility’s records office and request its form and privacy/authorization checklist.',
    'Submit the requested identity and authority documents and specify the treatment dates.',
    'Ask about clinician approval, assessed fees, and release arrangements; retain any receipt.',
    'Collect and check the authorized document and keep a copy for your records.',
], ['DOH hospital example: medical-record release procedure (not a Virac charter)' => $medical, 'DOH listing for EBMC in Virac' => $ebmc], 'General hospital-record guidance; confirm the treating facility’s own process', ['EBMC is listed in San Isidro Village, Virac. Request records there only if it treated the patient or holds the record. Do not apply another hospital’s fees or turnaround time to EBMC.']);

$guides['medCertSpouse'] = $guide('A public medical practitioner: ask Virac Municipal Health Office/RHU or the spouse’s treating government hospital for assessment/referral.', ['Spouse’s existing medical records and checklist wording.', 'Spouse/patient ID and requester authorization as required by the provider.'], [
    'Show MSWDO the requirement and clarify what medical finding the certificate must address.',
    'Contact the public health facility about consultation or review of the spouse’s condition and records.',
    'Attend the clinical assessment as instructed; provide the records requested by the physician.',
    'Ask whether further specialist assessment is needed and how to request the resulting certificate.',
    'Collect the physician-issued certificate and check its date, signature, and relevant findings.',
], ['Expanded Solo Parents Welfare Act' => $law, 'DOH Bicol facility directory (includes Virac RHU/EBMC)' => 'https://bicol.doh.gov.ph/certified-tb-dots-facilities/'], 'Public-practitioner requirement; local clinical assessment and fees to confirm', ['This is a clinical assessment, not a certificate a barangay can issue. The directory confirms facilities, not their incapacity-certificate procedure.']);

$guides['marriageOrAffidavit'] = $guide('PSA CRS Outlet Virac for a marriage certificate; MSWDO and a notary for the accepted affidavit alternative.', ['Civil-status details, your ID, and the exact checklist wording.'], [
    'Confirm with MSWDO which of the checklist’s two options applies to you.',
    'For a marriage certificate, book PSA Virac and request the record using both spouses’ names and marriage details.',
    'For an affidavit, obtain the accepted wording and arrange execution with the appropriate signers/IDs.',
    'Collect the selected document and prepare the required copy.',
], ['PSA marriage certificate' => 'https://psa.gov.ph/marriage-certificate', 'Virac PSA appointment rule' => $psaLocal, 'Affidavit identity/appearance guidance' => $notarial], 'Alternative routes; confirm which document MSWDO accepts for your circumstances');
$guides['marriageOrCenomar'] = $guide('PSA CRS Outlet Virac or PSA’s linked online-delivery services.', ['Your ID and either marriage details or the CENOMAR identity details.'], [
    'Confirm whether MSWDO needs a marriage certificate or CENOMAR for your situation.',
    'Book PSA Virac under the actual requester’s name, or choose a delivery service linked from PSA.',
    'Complete the selected record request, present the required identity/authority documents, and pay the assessed fee.',
    'Collect/check the document and prepare the copy named in your checklist.',
], ['PSA marriage records' => 'https://psa.gov.ph/marriage-certificate', 'PSA CENOMAR' => 'https://psa.gov.ph/cenomar', 'Virac appointment advisory' => $psaLocal, 'PSA online channels' => $psaRequests], 'PSA routes; confirm which civil-status record the checklist requires');
$guides['ofwRelationshipProof'] = $guide('PSA for the applicable civil-registry record; MSWDO for another accepted relationship document.', ['OFW’s and applicant’s names, relationship, and the relevant birth or marriage details.', 'Requester ID and any required authority to obtain another person’s record.'], [
    'Ask MSWDO which record demonstrates your relationship to the OFW.',
    'For a birth/marriage record, use the corresponding PSA guide and book the Virac outlet or use PSA’s linked delivery channels.',
    'If the checklist allows another form of proof, obtain MSWDO’s accepted-document instructions first.',
    'Check that the released record connects the correct people and prepare the required copy.',
], ['PSA civil-registry services' => $psaRequests, 'Requester authority' => $psaAuthority], 'PSA record route; other relationship evidence requires MSWDO confirmation');
$guides['ofwEmploymentContract'] = $guide('The OFW’s own files, employer, licensed recruitment agency, or the Migrant Workers Office (MWO) responsible for the country of work.', ['OFW’s name, passport/employment details, employer, and existing contract.', 'OFW’s authorization if an office requires it for release to a relative.'], [
    'Ask the OFW for all pages of the signed contract, including any verification page.',
    'If missing, ask the employer or recruitment agency for its copy. Provide the worker and employer details.',
    'If MSWDO requires a verified or equivalent employment record, ask DMW/MWO which office holds it and what authority is needed to obtain a copy.',
    'Follow that office’s current process and check that the record shows the relevant employment period.',
], ['DMW rules: worker’s contract copy' => 'https://dmw.gov.ph/archives/poea/agency/files/Licensing_2016_POEA%20Rules_Landbased.htm', 'DMW services charter' => 'https://dmw.gov.ph/resources/dmw/DMW-Citizens-Charter-1st-Edition-CY-2023.pdf'], 'Employment-record request guidance; MWO requirements vary by country', ['A standard blank contract template is not proof that this OFW is employed. Confirm which signed/verified or equivalent record MSWDO needs.']);
$guides['ofwPassportProof'] = $guide('The OFW’s passport/travel documents; Bureau of Immigration for a travel-records certification if that option is needed.', ['Passport identity page and relevant arrival/departure pages; the period MSWDO needs to establish.', 'BI’s current form/checklist and any required authority for a request concerning another person.'], [
    'Ask MSWDO whether the passport evidence satisfies the checklist before requesting BI certification.',
    'For the passport option, obtain clear copies of the relevant pages and check the covered dates with MSWDO.',
    'For BI certification, read BI’s form/checklist. The published service lists BI Main Office; confirm arrangements before travel from Catanduanes.',
    'Submit the completed form and attachments, pay against the Order of Payment, and retain the official receipt.',
    'Follow BI’s submission/claim-stub instructions and collect on the stated return date. Check the certified period.',
], ['BI travel-record certification: form, checklist, steps and fees' => 'https://immigration.gov.ph/services/travel-records-certification/'], 'BI national procedure; no Virac issuing counter was confirmed', ['A travel record shows movements. MSWDO decides whether the documents establish the overseas-work period required by your category.']);
$guides['ofwIncomeProof'] = $guide('Your employer/payroll office for income records; BIR for tax-document questions; MSWDO/your barangay for accepted evidence when unemployed or without a return.', ['The applicant spouse/family member’s income records and ID.', 'MSWDO’s accepted-document list for your employment situation.'], [
    'Ask MSWDO whose income and which period/document your checklist requires.',
    'If employed, request the specified income/employment certification or tax form from HR/payroll.',
    'If a tax document is required, use your filed record or ask the relevant BIR office about obtaining the accepted copy.',
    'If you have no such record, ask MSWDO about the appropriate alternative before seeking barangay certification.',
    'Check the name, income period, and issuer’s signature on the document you receive.',
], ['Income-proof examples and issuing entities in PAO’s charter' => $pao, 'BIR RDO directory (2023 listing)' => 'https://bir-cdn-preprod.e.gov.ph/local/pdf/RMC%20No.%2085-2023%20Annex%202.pdf'], 'Income-record preparation route; acceptable proof is decided by MSWDO', ['The BIR directory lists RDO 69 at San Isidro Village, Virac. Confirm the current counter and required service first. PAO’s income-proof examples are not a Virac Solo Parent checklist.']);

$guides['courtDecree'] = $guide('The issuing court for a civil decree, or the issuing church tribunal/office for a church record.', ['Existing decree, case/reference number, names, and your ID.'], [
    'Ask MSWDO which civil or church document your checklist calls for; explain which existing decree you hold.',
    'Contact that issuing court/tribunal’s records office for the appropriate certified copy.',
    'Follow its request, identity/authorization, payment, and collection instructions.',
    'Ask whether additional civil-status evidence is needed and collect the relevant certified pages.',
], ['Court directory' => $courts, 'Solo-parent documentary provisions' => $law], 'Existing-record route; issuing office and MSWDO must confirm acceptance', ['Do not assume a church document changes civil status or replaces a civil court order.']);
$guides['crimeRecord'] = $guide('The police station, treating medico-legal facility, or court that holds the existing record.', ['Your ID, case/incident/treatment reference, dates, and checklist wording.'], [
    'Ask MSWDO which of the listed records is applicable and sufficient for your situation.',
    'Identify the office holding the record: police for a report, the examining facility for a medical record, or the issuing court for case documents.',
    'Request the accepted copy and ask about privacy/authorization, official fees, and release arrangements.',
    'Collect and check the record’s dates, names, and certification before presenting it to MSWDO.',
], ['Court locator' => $courts, 'DOH medical-record example (not a Virac procedure)' => $medical, 'Solo-parent assistance provisions' => $law], 'Suggested records-request route; each issuer’s procedure must be confirmed');

$guides['abandonCert'] = $guide('Your Virac barangay office, with MSWDO guidance on the exact certification.', ['Your ID, residency details, relevant dates, and available supporting records.'], [
    'Show MSWDO the barangay-certificate requirement and obtain the accepted wording.',
    'Request the certificate from your barangay secretary or designated staff and explain the circumstances truthfully.',
    'Provide the information the barangay needs to verify and confirm who signs the certificate.',
    'Ask about charges and collection arrangements; check the signed certificate before presenting it to MSWDO.',
], ['National solo-parent documentary requirements' => $law], 'Suggested barangay-certificate route; local verification and wording to confirm', ['A barangay certification and an extract of an existing incident record are different documents. Follow the wording of your checklist.']);

return [
    'aliases' => ['detentionCert' => 'detentionCertificate', 'guardianship' => 'guardianshipProof', 'ofwContract' => 'ofwEmploymentContract', 'soleCareCert' => 'brgyCert', 'soloAffidavit' => 'soleCareAffidavit', 'travelRecord' => 'ofwPassportProof'],
    'guides' => $guides,
    'fallback' => $guide('MSWDO Virac, then the office it identifies for the requirement.', ['Your exact checklist and any existing supporting document.'], ['Show MSWDO the requirement and ask which office issues it.', 'Confirm the accepted wording, originals/copies, identity/authorization documents, and appointment arrangements.', 'Contact that issuer before visiting and follow its current request process.', 'Collect/check the document and keep the original and receipt.'], ['Virac MSWDO office page' => $municipal], 'Local procedure needs confirmation; no document-specific procedure was verified'),
    'offices' => [
        ['name' => 'MSWDO Virac', 'purpose' => 'Confirm your category, accepted documents and affidavit wording; request assistance/referrals.', 'location' => 'Ask the Virac LGU for the current MSWDO counter and schedule.', 'contact' => '', 'url' => $municipal, 'source' => 'Virac LGU office page'],
        ['name' => 'Your barangay office', 'purpose' => 'Residency, solo-parent or care certification, and existing barangay records.', 'location' => 'Barangay hall where you live in Virac.', 'contact' => '', 'url' => $law, 'source' => 'National documentary requirements; local process to confirm'],
        ['name' => 'PSA Catanduanes / CRS Outlet Virac', 'purpose' => 'PSA birth, marriage, death and CENOMAR requests.', 'location' => 'BL Jastrid Building, National Road, Cavinitan, Virac, Catanduanes 4800.', 'contact' => '0920 968 1179 (PSA directory)', 'url' => $psaDirectory, 'source' => 'PSA Bicol office directory; book CRS appointments separately'],
        ['name' => 'Virac Municipal Civil Registry Office', 'purpose' => 'Local events registered in Virac; ask about missing records or endorsement to PSA.', 'location' => 'Virac LGU building; confirm the current counter before visiting.', 'contact' => '', 'url' => $civilRegistry, 'source' => 'Virac LGU civil-registry office page'],
        ['name' => 'PAO Virac, Catanduanes District Office', 'purpose' => 'Ask about legal advice, document preparation and oath services you qualify for.', 'location' => 'DOJ Building, San Isidro Village, Virac, Catanduanes 4800.', 'contact' => '0917 245 5236 (2024 directory; confirm availability)', 'url' => $pao, 'source' => 'PAO 2024 charter, office directory p. 48'],
        ['name' => 'Issuing court / Virac court branches', 'purpose' => 'Certified copies of existing decisions and court orders.', 'location' => 'Use the court named in the decision; locate its branch in the official directory.', 'contact' => '', 'url' => $courts, 'source' => 'Supreme Court court locator'],
        ['name' => 'NACC / RACCO V', 'purpose' => 'Adoption and foster-care document inquiries for the Bicol region.', 'location' => '2nd Floor, Tyler Building, Rosal Street, Barangay 35-Tinago, Legazpi City, Albay.', 'contact' => '0917 191 6222 · racco5@nacc.gov.ph', 'url' => $nacc, 'source' => 'NACC 2026 charter, office directory p. 336; contact before mainland travel'],
        ['name' => 'Treating hospital / clinic', 'purpose' => 'Medical records; public-physician assessment through the appropriate health facility.', 'location' => 'Request from the facility holding the record. EBMC is listed at San Isidro Village, Virac.', 'contact' => '', 'url' => $ebmc, 'source' => 'DOH facility listing; records procedure must be confirmed with the facility'],
    ],
];
