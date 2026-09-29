/**
 * Preserve an existing Torus SEB condition in Moodle's availability editor.
 *
 * @module moodle-availability_torusseb-form
 */
M.availability_torusseb = M.availability_torusseb || {};
M.availability_torusseb.form = Y.Object(M.core_availability.plugin);
M.availability_torusseb.form.getNode = function() {
    return Y.Node.create('<span></span>').set('text', M.util.get_string('description', 'availability_torusseb'));
};
M.availability_torusseb.form.fillValue = function(value) {
    value.type = 'torusseb';
};
M.availability_torusseb.form.fillErrors = function() {};
