<form method="POST" action="/form/uncheck_hidden">

    <input type="text" name="wireless" value="mouse">

    <input type="hidden" name="coffee" value="123"> <!-- this should be discarded -->

    <!-- Do you need coffee ? (label) -->
    <input type="hidden" name="coffee" value="8569" disabled="disabled">
    <input type="hidden" name="coffee" value="8">
    <input type="hidden" name="coffee" value="0">
    <input type="checkbox" name="coffee" value="1" id="coffee-id" checked>

    <!-- check all other work as intended -->
    <input type="checkbox" name="tea" value="1" id="tea-id" checked>
    <input type="checkbox" name="vanilla" id="vanilla-id" checked>
    <input type="checkbox" name="butter" id="butter-id" >

    <button type="submit">Submit Preference</button>
</form>
